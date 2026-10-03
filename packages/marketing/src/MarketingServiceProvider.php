<?php

declare(strict_types=1);

namespace Odden\Marketing;

use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Events\CompaniesMerged;
use Odden\Core\Events\ContactCreated;
use Odden\Core\Events\ContactsMerged;
use Odden\Core\Models\Contact;
use Odden\Core\Models\ListMembership;
use Odden\Core\Support\ModelRegistry;
use Odden\MailBuilder\MergeTags\MergeTagRegistry;
use Odden\Marketing\Actions\EnrollContactInWorkflowAction;
use Odden\Marketing\Console\Commands\DecayLeadScoresCommand;
use Odden\Marketing\Console\Commands\DispatchScheduledCampaignsCommand;
use Odden\Marketing\Console\Commands\EvaluateAbTestsCommand;
use Odden\Marketing\Console\Commands\ProcessWorkflowsCommand;
use Odden\Marketing\Console\Commands\SunsetInactiveSubscribersCommand;
use Odden\Marketing\Listeners\MoveMergedRecords;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\CustomBehavioralEvent;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\LeadDecayLog;
use Odden\Marketing\Models\LeadScoreLog;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\WorkflowEnrollment;

class MarketingServiceProvider extends ServiceProvider
{
    private static bool $ampCorsSkipRegistered = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/odden-marketing.php',
            'odden-marketing'
        );

        $this->callAfterResolving(ModelRegistry::class, function (ModelRegistry $registry): void {
            $registry->discover(__DIR__.'/Models', 'Odden\\Marketing\\Models');
        });

        $this->callAfterResolving(MergeTagRegistry::class, function (MergeTagRegistry $registry): void {
            $this->registerMergeTags($registry);
        });
    }

    /**
     * Register CRM merge tags (contact, company, sender) with the mail builder.
     */
    protected function registerMergeTags(MergeTagRegistry $registry): void
    {
        $registry
            ->register('Contact', [
                '{{contact.first_name}}' => 'Recipient first name (e.g. Sarah)',
                '{{contact.last_name}}' => 'Recipient last name (e.g. Connor)',
                '{{contact.full_name}}' => 'Recipient full name (e.g. Sarah Connor)',
                '{{contact.email}}' => 'Recipient email address',
                '{{contact.job_title}}' => 'Recipient professional title (e.g. VP of Operations)',
                '{{contact.phone}}' => 'Recipient direct phone number',
                '{{contact.lifecycle_stage}}' => 'Current CRM lifecycle stage (e.g. Customer, Lead)',
            ], ['contact' => [
                'first_name' => 'Alex',
                'last_name' => 'Morgan',
                'full_name' => 'Alex Morgan',
                'email' => 'alex.morgan@acme.com',
                'job_title' => 'Chief Technology Officer',
                'phone' => '+1 (555) 234-5678',
                'lifecycle_stage' => 'customer',
            ]])
            ->register('Company', [
                '{{company.name}}' => 'Associated company name (e.g. Acme Corporation)',
                '{{company.domain}}' => 'Company corporate domain (e.g. acme.com)',
                '{{company.industry}}' => 'Company industry vertical (e.g. Software & Technology)',
            ], ['company' => [
                'name' => 'Acme Corporation',
                'domain' => 'acme.com',
                'industry' => 'Artificial Intelligence & Robotics',
            ]])
            ->register('Event', [
                '{{event.<id>.rsvp_token}}' => 'Signed RSVP token for event <id>, for the AMP RSVP form (e.g. {{event.12.rsvp_token}})',
            ], ['event' => [12 => ['rsvp_token' => '1.sample-rsvp-token']]])
            ->register('Sender / Owner', [
                '{{sender.name}}' => 'Assigned account executive or sender name',
                '{{sender.email}}' => 'Sender reply-to email address',
            ], ['sender' => [
                'name' => 'Alex Rivera',
                'email' => 'alex@example.com',
            ]]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-marketing');
        if (config('odden-marketing.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            $this->skipGlobalCorsForAmpRoutes();
        }

        // Workflow triggers that come from Core: a contact created through CreateContactAction, and a
        // contact joining an audience list.
        Event::listen(ContactCreated::class, function (ContactCreated $event): void {
            app(EnrollContactInWorkflowAction::class)->triggerContactCreatedWorkflows($event->contact);
        });

        ListMembership::created(function (ListMembership $membership): void {
            if ($membership->member_type !== (new Contact)->getMorphClass()) {
                return;
            }

            /** @var Contact|null $contact */
            $contact = Contact::query()->find($membership->member_id);
            if ($contact !== null) {
                app(EnrollContactInWorkflowAction::class)->triggerListJoinedWorkflows($contact, (int) $membership->list_id);
            }
        });

        // Dynamic Eloquent relations on Contact
        if (class_exists(Contact::class)) {
            Contact::resolveRelationUsing('formSubmissions', function (Contact $contact) {
                return $contact->hasMany(FormSubmission::class, 'contact_id');
            });

            Contact::resolveRelationUsing('campaignRecipients', function (Contact $contact) {
                return $contact->hasMany(CampaignRecipient::class, 'contact_id');
            });

            Contact::resolveRelationUsing('marketingSubscription', function (Contact $contact) {
                return $contact->hasOne(MarketingSubscription::class, 'contact_id');
            });

            Contact::resolveRelationUsing('leadScoreLogs', function (Contact $contact) {
                return $contact->hasMany(LeadScoreLog::class, 'contact_id')->orderBy('created_at', 'desc');
            });

            Contact::resolveRelationUsing('leadDecayLogs', function (Contact $contact) {
                return $contact->hasMany(LeadDecayLog::class, 'contact_id')->orderBy('created_at', 'desc');
            });

            Contact::resolveRelationUsing('workflowEnrollments', function (Contact $contact) {
                return $contact->hasMany(WorkflowEnrollment::class, 'contact_id');
            });

            Contact::resolveRelationUsing('customBehavioralEvents', function (Contact $contact) {
                return $contact->hasMany(CustomBehavioralEvent::class, 'contact_id');
            });
        }

        // Synchronous on purpose: these run inside Core's merge transaction.
        Event::listen(ContactsMerged::class, [MoveMergedRecords::class, 'handleContactsMerged']);
        Event::listen(CompaniesMerged::class, [MoveMergedRecords::class, 'handleCompaniesMerged']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchScheduledCampaignsCommand::class,
                ProcessWorkflowsCommand::class,
                EvaluateAbTestsCommand::class,
                DecayLeadScoresCommand::class,
                SunsetInactiveSubscribersCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/odden-marketing.php' => config_path('odden-marketing.php'),
            ], 'odden-marketing-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'odden-marketing-migrations');
        }
    }

    /**
     * The in-email AMP endpoints set their own CORS headers from an origin
     * allow-list (odden-marketing.amp.allowed_origins). Keep the app's global
     * CORS middleware (config/cors.php, which matches "api/*" by default) from
     * replacing them with its own, usually wildcard, headers.
     */
    protected function skipGlobalCorsForAmpRoutes(): void
    {
        if (self::$ampCorsSkipRegistered) {
            return;
        }

        self::$ampCorsSkipRegistered = true;

        HandleCors::skipWhen(static function (Request $request): bool {
            if (! str_ends_with($request->path(), 'amp/feedback') && ! str_ends_with($request->path(), 'amp/rsvp')) {
                return false;
            }

            foreach (['odden.marketing.amp.feedback', 'odden.marketing.amp.rsvp'] as $name) {
                $route = app('router')->getRoutes()->getByName($name);

                if ($route instanceof Route && trim($route->uri(), '/') === trim($request->path(), '/')) {
                    return true;
                }
            }

            return false;
        });
    }
}
