<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\DealResource\Pages\KanbanDeals;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use PHPUnit\Framework\Attributes\DataProvider;

class PageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each custom page, and a model whose policy's viewAny gates it.
     *
     * @return array<string, array{string, class-string}>
     */
    public static function pages(): array
    {
        return [
            'executive overview' => ['/admin/executive-overview', Deal::class],
            'data quality (contacts)' => ['/admin/data-quality', Contact::class],
            'data quality (companies)' => ['/admin/data-quality', Company::class],
            'sales cockpit' => ['/admin/sales-cockpit', Deal::class],
            'deal board' => ['/admin/deals/board', Deal::class],
        ];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('pages')]
    public function test_page_returns_403_when_policy_denies_view_any(string $url, string $model): void
    {
        $this->denyAbilities([$model], ['viewAny']);

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('pages')]
    public function test_page_is_accessible_when_policy_allows_view_any(string $url, string $model): void
    {
        $this->denyAbilities([$model], []);

        $this->actingAs(User::factory()->create())->get($url)->assertSuccessful();
    }

    public function test_pages_are_hidden_from_navigation_when_policy_denies_view_any(): void
    {
        $this->denyAbilities([Contact::class, Deal::class], ['viewAny']);

        $this->actingAs(User::factory()->create());

        $this->assertFalse(SalesCockpit::canAccess());
        $this->assertFalse(DataQuality::canAccess());
        $this->assertFalse(KanbanDeals::canAccess());

        $this->get('/admin/contacts')->assertForbidden();
        $this->get('/admin')->assertSuccessful()
            ->assertDontSee('Sales Cockpit')
            ->assertDontSee('Data Quality');
    }

    public function test_livewire_requests_to_page_are_rejected_when_policy_denies_view_any(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(SalesCockpit::class);

        $this->denyAbilities([Deal::class], ['viewAny']);

        $component->call('setTab', 'all')->assertForbidden();
    }

    public function test_pages_require_an_authenticated_user(): void
    {
        $this->assertFalse(SalesCockpit::canAccess());
        $this->assertFalse(DataQuality::canAccess());
    }
}
