<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\AdAudienceSyncResource\Pages\ListAdAudienceSyncs;
use Odden\Filament\Resources\CampaignResource\Pages\ListCampaigns;
use Odden\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Marketing\Contracts\PublishesAdAudience;
use Odden\Marketing\Contracts\SuggestsSubjectLines;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Models\AdAudienceSync;
use Odden\Marketing\Models\Campaign;

/**
 * The panel asks the container for the briefing, the subject-line writer and the audience publisher, so an application or
 * add-on that rebinds a contract changes what the panel shows without touching the panel.
 */
class ReplaceableActionsTest extends TestCase
{
    use RefreshDatabase;

    /** How many times the replacement summarizer was asked for a briefing. */
    public static int $briefings = 0;

    private function bindSummarizer(): void
    {
        self::$briefings = 0;

        $this->app->bind(SummarizesTimeline::class, fn () => new class implements SummarizesTimeline
        {
            public function execute(Contact|Company $subject): array
            {
                ReplaceableActionsTest::$briefings++;

                return ['title' => 'Replacement briefing', 'sentiment' => 'neutral', 'executive_summary' => 'WRITTEN BY THE REPLACEMENT', 'key_milestones' => [], 'recommended_next_action' => 'Do the thing', 'touchpoints_analyzed' => 0];
            }
        });
    }

    public function test_the_contact_briefing_comes_from_whatever_summarizer_is_bound(): void
    {
        $this->bindSummarizer();
        $contact = Contact::factory()->create();

        $table = Livewire::actingAs(User::factory()->create())->test(ListContacts::class)->instance()->getTable();
        $html = $table->getAction('ai_briefing')->record($contact)->getModalContent()->render();

        $this->assertStringContainsString('WRITTEN BY THE REPLACEMENT', $html);
        $this->assertGreaterThan(0, self::$briefings, 'The panel asked the bound summarizer, not a built-in class');
    }

    public function test_the_company_briefing_comes_from_whatever_summarizer_is_bound(): void
    {
        $this->bindSummarizer();
        $company = Company::factory()->create();

        $table = Livewire::actingAs(User::factory()->create())->test(ListCompanies::class)->instance()->getTable();
        $html = $table->getAction('ai_briefing')->record($company)->getModalContent()->render();

        $this->assertStringContainsString('WRITTEN BY THE REPLACEMENT', $html);
        $this->assertGreaterThan(0, self::$briefings, 'The panel asked the bound summarizer, not a built-in class');
    }

    public function test_the_campaign_assistant_uses_whatever_subject_line_writer_is_bound(): void
    {
        $this->app->bind(SuggestsSubjectLines::class, fn () => new class implements SuggestsSubjectLines
        {
            public function execute(string $topic, string $tone = 'engaging', ?string $audience = null): array
            {
                return ['suggestions' => ['Replacement subject'], 'variant_b' => 'Replacement variant B', 'preview_text' => 'Replacement preview', 'rationale' => 'r'];
            }
        });
        $campaign = Campaign::create(['name' => 'Launch', 'subject' => 'Live now', 'sender_name' => 'Odden', 'sender_email' => 'news@odden.test', 'status' => CampaignStatus::Draft]);

        Livewire::actingAs(User::factory()->create())
            ->test(ListCampaigns::class)
            ->mountTableAction('aiSubjectAssistant', $campaign)
            ->assertTableActionDataSet(['variant_b_subject' => 'Replacement variant B', 'preview_text' => 'Replacement preview']);
    }

    public function test_sync_now_uses_whatever_audience_publisher_is_bound_and_shows_its_message(): void
    {
        $this->app->bind(PublishesAdAudience::class, fn () => new class implements PublishesAdAudience
        {
            public function execute(AdAudienceSync $sync): array
            {
                return ['platform' => $sync->platform, 'records_synced' => 3, 'audience_id' => 'aud-1', 'message' => 'Uploaded 3 members to Meta.'];
            }
        });
        $sync = AdAudienceSync::create(['name' => 'Meta: buyers', 'platform' => 'meta', 'list_id' => CrmList::create(['name' => 'Buyers', 'type' => 'static'])->id, 'is_active' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(ListAdAudienceSyncs::class)
            ->callTableAction('syncNow', $sync)
            ->assertNotified(Notification::make()->title('Ad Audience Synchronized')->body('Uploaded 3 members to Meta.')->success());
    }

    public function test_sync_now_keeps_its_default_wording_with_the_built_in_publisher(): void
    {
        $sync = AdAudienceSync::create(['name' => 'LinkedIn: accounts', 'platform' => 'linkedin', 'list_id' => CrmList::create(['name' => 'Accounts', 'type' => 'static'])->id, 'is_active' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(ListAdAudienceSyncs::class)
            ->callTableAction('syncNow', $sync)
            ->assertNotified(Notification::make()->title('Ad Audience Synchronized')->body('Generated SHA-256 privacy hashes for 0 contacts on linkedin.')->success());
    }
}
