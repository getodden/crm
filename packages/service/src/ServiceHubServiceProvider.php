<?php

declare(strict_types=1);

namespace Odden\Service;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Events\CompaniesMerged;
use Odden\Core\Events\ContactsMerged;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ModelRegistry;
use Odden\Service\Actions\DraftTicketReplyAction;
use Odden\Service\Contracts\DraftsTicketReply;
use Odden\Service\Listeners\MoveMergedRecords;
use Odden\Service\Models\Ticket;

class ServiceHubServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/odden-service.php',
            'odden-service'
        );

        // bindIf: an add-on's own bind() wins whichever provider registers first.
        $this->app->bindIf(DraftsTicketReply::class, DraftTicketReplyAction::class);

        $this->callAfterResolving(ModelRegistry::class, function (ModelRegistry $registry): void {
            $registry->discover(__DIR__.'/Models', 'Odden\\Service\\Models');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-service');
        if (config('odden-service.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if (class_exists(Contact::class)) {
            Contact::resolveRelationUsing('tickets', function (Contact $contact) {
                return $contact->hasMany(Ticket::class, 'contact_id');
            });
        }

        if (class_exists(Company::class)) {
            Company::resolveRelationUsing('tickets', function (Company $company) {
                return $company->hasMany(Ticket::class, 'company_id');
            });
        }

        // Synchronous on purpose: these run inside Core's merge transaction.
        Event::listen(ContactsMerged::class, [MoveMergedRecords::class, 'handleContactsMerged']);
        Event::listen(CompaniesMerged::class, [MoveMergedRecords::class, 'handleCompaniesMerged']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\CheckSlaBreachesCommand::class,
                Console\Commands\RunServiceAutomationsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/odden-service.php' => config_path('odden-service.php'),
            ], 'odden-service-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'odden-service-migrations');

            $this->publishes([
                __DIR__.'/../resources/js/widget.js' => public_path('js/odden-chat-widget.js'),
            ], 'odden-service-widget');
        }
    }
}
