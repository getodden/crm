<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\ApplyLeadScoringEventAction;
use Odden\Marketing\Actions\DecayInactiveLeadScoresAction;
use Odden\Marketing\Actions\HandoffLeadToSalesAction;
use Odden\Marketing\Actions\ProcessFormSubmissionAction;
use Odden\Marketing\Actions\RecordWebVisitAction;
use Odden\Marketing\Actions\TrackCustomBehavioralEventAction;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\LeadScoreLog;
use Odden\Marketing\Models\LeadScoringRule;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Tests\Fixtures\User;

class LeadScoringRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_conditions_pick_the_matching_rule_over_the_catch_all(): void
    {
        LeadScoringRule::create(['name' => 'Catch-all', 'event_type' => LeadScoringEventType::FormSubmission, 'score_change' => 5]);
        $software = LeadScoringRule::create([
            'name' => 'Software companies',
            'event_type' => LeadScoringEventType::FormSubmission,
            'score_change' => 30,
            'conditions' => [['field' => 'company.industry', 'operator' => '=', 'value' => 'Software']],
        ]);
        $pricing = LeadScoringRule::create([
            'name' => 'Pricing page forms',
            'event_type' => LeadScoringEventType::FormSubmission,
            'score_change' => 12,
            'conditions' => ['context.page' => ['/pricing', '/enterprise']],
        ]);

        $action = app(ApplyLeadScoringEventAction::class);

        $softwareContact = Contact::factory()->create(['lead_score' => 0]);
        $softwareContact->associateWith(Company::factory()->create(['industry' => 'Software']));
        $action->execute($softwareContact, LeadScoringEventType::FormSubmission);
        $this->assertSame(30, $softwareContact->fresh()->lead_score);
        $this->assertSame($software->id, LeadScoreLog::query()->where('contact_id', $softwareContact->id)->value('rule_id'));

        $pricingContact = Contact::factory()->create(['lead_score' => 0]);
        $action->execute($pricingContact, LeadScoringEventType::FormSubmission, context: ['page' => '/pricing']);
        $this->assertSame(12, $pricingContact->fresh()->lead_score);
        $this->assertSame($pricing->id, LeadScoreLog::query()->where('contact_id', $pricingContact->id)->value('rule_id'));

        $other = Contact::factory()->create(['lead_score' => 0]);
        $action->execute($other, LeadScoringEventType::FormSubmission, context: ['page' => '/blog']);
        $this->assertSame(5, $other->fresh()->lead_score, 'No condition matches, so the unconditional rule applies');
    }

    public function test_numeric_and_missing_value_conditions(): void
    {
        LeadScoringRule::create(['name' => 'Big accounts', 'event_type' => LeadScoringEventType::EmailClicked, 'score_change' => 40, 'conditions' => [['field' => 'contact.lead_score', 'operator' => '>=', 'value' => 50]]]);

        $action = app(ApplyLeadScoringEventAction::class);

        $high = Contact::factory()->create(['lead_score' => 60]);
        $action->execute($high, LeadScoringEventType::EmailClicked);
        $this->assertSame(100, $high->fresh()->lead_score);

        // A condition on a value the contact doesn't have never matches; with no fallback rule the default applies.
        $low = Contact::factory()->create(['lead_score' => 10]);
        $action->execute($low, LeadScoringEventType::EmailClicked);
        $this->assertSame(20, $low->fresh()->lead_score);
    }

    public function test_high_intent_page_visits_apply_their_bonus_points(): void
    {
        foreach (['/pricing' => 20, '/demo' => 15, '/enterprise' => 25] as $path => $bonus) {
            $contact = Contact::factory()->create(['lead_score' => 0]);

            app(RecordWebVisitAction::class)->execute([
                'visitor_token' => 'visitor-'.ltrim($path, '/'),
                'contact_id' => $contact->id,
                'url' => 'https://example.com'.$path,
                'path' => $path,
            ]);

            $this->assertSame($bonus, $contact->fresh()->lead_score, "Visit to {$path}");
        }
    }

    public function test_mql_and_sql_thresholds_come_from_config(): void
    {
        config(['odden-marketing.sales_handoff.mql_score_threshold' => 30, 'odden-marketing.sales_handoff.sql_score_threshold' => 60, 'odden-marketing.sales_handoff.auto_handoff_on_sql' => false]);

        $contact = Contact::factory()->create(['lead_score' => 20, 'lifecycle_stage' => LifecycleStage::Lead]);
        app(ApplyLeadScoringEventAction::class)->execute($contact, LeadScoringEventType::PropertyMatch, points: 12);
        $this->assertSame(LifecycleStage::MarketingQualifiedLead, $contact->fresh()->lifecycle_stage);

        // Decay demotes against the same thresholds: an SQL at 70 that decays to 55 is below the configured 60.
        $sql = Contact::factory()->create(['lead_score' => 70, 'lifecycle_stage' => LifecycleStage::SalesQualifiedLead, 'lead_score_updated_at' => now()->subDays(45)]);
        app(DecayInactiveLeadScoresAction::class)->execute(30, 15);
        $this->assertSame(55, $sql->fresh()->lead_score);
        $this->assertSame(LifecycleStage::MarketingQualifiedLead, $sql->fresh()->lifecycle_stage);
    }

    public function test_auto_captured_forms_log_their_score_change(): void
    {
        $this->postJson(route('odden.marketing.forms.auto-capture'), ['email' => 'logged@example.com', 'name' => 'Log Ged'])->assertOk();
        $this->postJson(route('odden.marketing.forms.auto-capture'), ['email' => 'logged@example.com'])->assertOk();

        $contact = Contact::query()->where('email', 'logged@example.com')->firstOrFail();
        $logs = LeadScoreLog::query()->where('contact_id', $contact->id)->orderBy('id')->get();

        $this->assertSame([15, 10], $logs->pluck('score_change')->all());
        $this->assertSame(25, $contact->lead_score);
        $this->assertSame(25, $logs->last()->score_after);
    }

    public function test_custom_events_default_to_five_points(): void
    {
        $contact = Contact::factory()->create(['lead_score' => 0]);

        app(TrackCustomBehavioralEventAction::class)->execute('feature_used', $contact);

        $this->assertSame(5, $contact->fresh()->lead_score);
    }

    public function test_company_intent_includes_the_points_of_the_form_submission_that_just_happened(): void
    {
        $company = Company::factory()->create(['name' => 'Intent Corp', 'domain' => 'intentcorp.test', 'intent_score' => 0]);
        $form = MarketingForm::create([
            'title' => 'Contact',
            'slug' => 'contact-intent',
            'fields_schema' => [
                ['name' => 'first_name', 'type' => 'text'],
                ['name' => 'email', 'type' => 'email'],
                ['name' => 'company', 'type' => 'text'],
            ],
        ]);

        $submission = app(ProcessFormSubmissionAction::class)->execute($form, ['first_name' => 'Ivy', 'email' => 'ivy@intentcorp.test', 'company' => 'Intent Corp']);

        $contact = Contact::query()->findOrFail($submission->contact_id);
        $this->assertSame(15, $contact->lead_score);
        $this->assertGreaterThanOrEqual(15, $company->fresh()->intent_score, 'Company intent was calculated before the form points were added');
    }

    public function test_hand_off_owners_rotate_through_the_users(): void
    {
        $users = User::factory()->count(3)->create();

        $owners = [];
        foreach (range(1, 4) as $i) {
            $contact = Contact::factory()->create(['owner_id' => null]);
            $result = app(HandoffLeadToSalesAction::class)->execute($contact);
            $owners[] = $result['contact']->owner_id;
        }

        $ids = $users->pluck('id')->all();
        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[0]], $owners);
    }
}
