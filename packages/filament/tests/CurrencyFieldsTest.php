<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\CreateDeal;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Resources\DealResource\RelationManagers\DealProductsRelationManager;
use Odden\Filament\Resources\SalesQuotaResource\Pages\CreateSalesQuota;
use Odden\Filament\Resources\SalesQuotaResource\Pages\EditSalesQuota;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesQuota;

/**
 * Deal and quota forms default to the configured currency (#87) and show the record's currency, not a fixed "$" (#119).
 */
class CurrencyFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function prefixIs(string $expected): \Closure
    {
        return fn (TextInput $field): bool => $field->getPrefixLabel() === $expected;
    }

    public function test_a_new_deal_defaults_to_the_configured_currency_and_its_amount_follows_the_selection(): void
    {
        config()->set('odden-sales.default_currency', 'JPY');

        Livewire::actingAs(User::factory()->create())
            ->test(CreateDeal::class)
            ->assertSchemaStateSet(['currency' => 'JPY'])
            ->assertFormFieldExists('amount', $this->prefixIs('¥'))
            ->assertFormFieldExists('currency', fn (Select $field): bool => array_key_exists('JPY', $field->getOptions()))
            ->set('data.currency', 'EUR')
            ->assertFormFieldExists('amount', $this->prefixIs('€'))
            ->set('data.currency', 'CAD')
            ->assertFormFieldExists('amount', $this->prefixIs('CAD '));
    }

    public function test_editing_a_deal_keeps_its_currency_and_shows_it(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id, 'currency' => 'CHF']);

        Livewire::actingAs(User::factory()->create())
            ->test(EditDeal::class, ['record' => $deal->id])
            ->assertSchemaStateSet(['currency' => 'CHF'])
            ->assertFormFieldExists('amount', $this->prefixIs('CHF '))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('CHF', $deal->refresh()->currency);
    }

    public function test_deal_product_prices_use_the_deal_currency(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id, 'currency' => 'GBP']);

        Livewire::actingAs(User::factory()->create())
            ->test(DealProductsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldExists('unit_price', $this->prefixIs('£'));
    }

    public function test_a_quota_takes_a_currency_defaulting_to_the_configured_one(): void
    {
        config()->set('odden-sales.default_currency', 'EUR');
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateSalesQuota::class)
            ->assertSchemaStateSet(['currency' => 'EUR'])
            ->assertFormFieldExists('target_amount', $this->prefixIs('€'))
            ->fillForm([
                'user_id' => $user->id,
                'period_type' => 'monthly',
                'target_amount' => 5000,
                'currency' => 'gbp',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
            ])
            ->assertFormFieldExists('target_amount', $this->prefixIs('£'))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('GBP', SalesQuota::query()->sole()->currency);
    }

    public function test_a_quota_currency_must_be_a_three_letter_code(): void
    {
        $user = User::factory()->create();
        $quota = SalesQuota::query()->create([
            'user_id' => $user->id,
            'period_type' => 'monthly',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'target_amount' => 1000,
            'currency' => 'CHF',
        ]);

        $component = Livewire::actingAs($user)
            ->test(EditSalesQuota::class, ['record' => $quota->id])
            ->assertSchemaStateSet(['currency' => 'CHF'])
            ->assertFormFieldExists('target_amount', $this->prefixIs('CHF '));

        foreach (['', 'EU', 'EURO', '€€€', '12A'] as $invalid) {
            $component->fillForm(['currency' => $invalid])->call('save')->assertHasFormErrors(['currency']);
        }

        $this->assertSame('CHF', $quota->refresh()->currency);
    }
}
