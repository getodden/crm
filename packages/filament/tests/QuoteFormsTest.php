<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Resources\DealResource\RelationManagers\QuotesRelationManager;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Resources\QuoteResource\Pages\CreateQuote;
use Odden\Filament\Resources\QuoteResource\Pages\EditQuote;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;

/**
 * Accepted is reached only through Accept & Sign, and a signed quote stays as signed (#108); a quote takes its
 * deal's currency and owner (#118).
 */
class QuoteFormsTest extends TestCase
{
    use RefreshDatabase;

    private function deal(array $attributes = []): Deal
    {
        $pipeline = Pipeline::factory()->withStages()->create();

        return Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id] + $attributes);
    }

    public function test_the_quote_form_cannot_set_a_quote_to_accepted(): void
    {
        $quote = Quote::factory()->create(['deal_id' => $this->deal()->id, 'status' => QuoteStatus::Sent]);

        Livewire::actingAs(User::factory()->create())
            ->test(EditQuote::class, ['record' => $quote->id])
            ->set('data.status', QuoteStatus::Accepted->value)
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);
    }

    public function test_the_deal_quotes_tab_cannot_accept_edit_or_delete_a_signed_quote(): void
    {
        $deal = $this->deal();
        $open = Quote::factory()->create(['deal_id' => $deal->id, 'status' => QuoteStatus::Sent]);
        $signed = Quote::factory()->create(['deal_id' => $deal->id, 'status' => QuoteStatus::Accepted]);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(QuotesRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->assertActionHidden(TestAction::make('edit')->table($signed))
            ->assertActionHidden(TestAction::make('delete')->table($signed))
            ->assertActionVisible(TestAction::make('edit')->table($open))
            ->callAction(TestAction::make('edit')->table($open), ['status' => QuoteStatus::Accepted->value])
            ->assertHasFormErrors(['status']);

        $this->assertSame(QuoteStatus::Sent, $open->refresh()->status);
        $this->assertNotNull($signed->fresh());
        $this->assertFalse(QuoteResource::canDelete($signed));
    }

    public function test_a_quote_created_on_the_deal_tab_takes_the_deal_currency_and_owner(): void
    {
        $owner = User::factory()->create();
        $deal = $this->deal(['currency' => 'EUR', 'owner_id' => $owner->id]);

        Livewire::actingAs(User::factory()->create())
            ->test(QuotesRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callAction(TestAction::make('create')->table(), ['title' => 'Euro proposal', 'status' => QuoteStatus::Draft->value])
            ->assertHasNoFormErrors();

        $quote = Quote::query()->where('title', 'Euro proposal')->firstOrFail();
        $this->assertSame('EUR', $quote->currency);
        $this->assertSame($owner->id, $quote->user_id);
    }

    public function test_a_quote_created_from_the_quote_page_takes_the_deal_currency_and_owner(): void
    {
        $owner = User::factory()->create();
        $deal = $this->deal(['currency' => 'GBP', 'owner_id' => $owner->id]);

        Livewire::actingAs(User::factory()->create())
            ->test(CreateQuote::class)
            ->fillForm(['deal_id' => $deal->id, 'title' => 'Sterling proposal', 'status' => QuoteStatus::Draft->value, 'items' => []])
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::query()->where('title', 'Sterling proposal')->firstOrFail();
        $this->assertSame('GBP', $quote->currency);
        $this->assertSame($owner->id, $quote->user_id);
    }

    public function test_a_line_item_discount_must_be_a_percentage(): void
    {
        $deal = $this->deal();

        Livewire::actingAs(User::factory()->create())
            ->test(CreateQuote::class)
            ->fillForm([
                'deal_id' => $deal->id,
                'title' => 'Too generous',
                'status' => QuoteStatus::Draft->value,
                'items' => [['name' => 'Seat', 'unit_price' => 10, 'quantity' => 1, 'discount_percent' => 150]],
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, Quote::query()->count());
    }
}
