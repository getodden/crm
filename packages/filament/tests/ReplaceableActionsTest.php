<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Tests\Fixtures\User;

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
}
