<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Livewire\Contracts\SearchSource;
use Odden\Livewire\Search\GlobalSearch;
use Odden\Livewire\Search\SearchHit;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

class GlobalSearchTest extends RoutedTestCase
{
    protected function drawViewScreens(): void
    {
        FixtureScreensServiceProvider::drawInFixtureShell('contacts.view', 'companies.index', 'companies.view');
    }

    protected function holdNoAreasAndDrawViewScreens(): void
    {
        $this->drawViewScreens();
        $this->holdNoAreas();
    }

    protected function holdNoAreas(): void
    {
        app()->instance(AreaAccess::class, new class implements AreaAccess
        {
            public function allows(DataArea $area, int|string|null $userId = null): bool
            {
                return false;
            }

            public function mayReveal(DataArea $area, int|string|null $userId = null): bool
            {
                return false;
            }
        });
        app()->forgetInstance(Areas::class);
    }

    private function search(string $term): Testable
    {
        return Livewire::actingAs(User::factory()->create())->test(GlobalSearch::class)->set('search', $term);
    }

    public function test_a_term_under_two_characters_returns_no_groups(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->search('G')->assertViewHas('groups', [])->assertViewHas('searched', false)->assertDontSee('No results');
        $this->search('  G ')->assertViewHas('groups', []);
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_a_contact_found_through_the_index_links_to_its_view_screen(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.com']);
        Contact::factory()->create(['first_name' => 'Alan', 'last_name' => 'Turing']);

        $this->search('Gra')
            ->assertSee('Contacts')
            ->assertSee('Grace Hopper')
            ->assertSee('grace@example.com')
            ->assertSee('href="https://fixture.test/contacts.view"', false)
            ->assertDontSee('Alan Turing')
            ->assertViewHas('count', 1);
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_a_company_is_found_by_the_start_of_its_name(): void
    {
        Company::factory()->create(['name' => 'Initech', 'domain' => 'initech.example']);

        $this->search('Initech')
            ->assertSee('Companies')
            ->assertSee('Initech')
            ->assertSee('initech.example');
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_each_source_is_limited_to_five_hits(): void
    {
        Contact::factory()->count(7)->create(['first_name' => 'Zed']);

        $groups = $this->search('Zed')->viewData('groups');

        $this->assertCount(5, $groups[0]['hits']);
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_a_user_who_cannot_open_the_index_gets_no_group_for_it(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $this->denyAbilities([Contact::class], ['viewAny']);

        $this->search('Grace')->assertViewHas('groups', [])->assertDontSee('Grace Hopper')->assertSee('No results');
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_a_hit_whose_view_screen_the_user_cannot_open_is_dropped(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $this->denyAbilities([Contact::class], ['view']);

        $this->search('Grace')->assertViewHas('groups', [])->assertDontSee('Grace Hopper');
    }

    public function test_a_hit_is_dropped_when_no_shell_draws_the_view_screen(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->search('Grace')->assertViewHas('groups', [])->assertDontSee('Grace Hopper');
    }

    #[DefineEnvironment('holdNoAreasAndDrawViewScreens')]
    public function test_a_user_without_the_identity_area_finds_nothing(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        Company::factory()->create(['name' => 'Initech']);

        $this->search('Grace')->assertViewHas('groups', []);
        $this->search('Initech')->assertViewHas('groups', []);
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_a_configured_source_is_used(): void
    {
        config(['odden-livewire.search.sources' => [FakeSearchSource::class]]);
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->search('anything')
            ->assertSee('Gadgets')
            ->assertSee('Gadget anything')
            ->assertDontSee('Grace Hopper');
    }

    public function test_a_source_that_does_not_implement_the_contract_throws(): void
    {
        config(['odden-livewire.search.sources' => [stdClass::class]]);

        $this->expectException(InvalidArgumentException::class);

        $this->search('anything');
    }

    public function test_the_term_is_capped_at_one_hundred_characters(): void
    {
        $this->search(str_repeat('é', 150))->assertSet('search', str_repeat('é', 100));
    }

    public function test_results_are_not_stored_in_public_properties(): void
    {
        $public = array_map(
            fn (ReflectionProperty $property): string => $property->getName(),
            array_filter(
                (new ReflectionClass(GlobalSearch::class))->getProperties(ReflectionProperty::IS_PUBLIC),
                fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === GlobalSearch::class && ! $property->isStatic(),
            ),
        );

        $this->assertSame(['search'], array_values($public));
    }

    #[DefineEnvironment('drawViewScreens')]
    public function test_the_search_box_is_in_the_header_for_signed_in_users_when_enabled(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('Ctrl K')
            ->assertSee('data-ui-modal="global-search"', false)
            ->assertSee('keydown.window.meta.k.prevent', false)
            ->assertSee('keydown.window.ctrl.k.prevent', false);

        config(['odden-livewire.search.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertDontSee('data-ui-modal="global-search"', false);
    }
}

final class FakeSearchSource implements SearchSource
{
    public function label(): string
    {
        return 'Gadgets';
    }

    public function screenKey(): string
    {
        return 'contacts.index';
    }

    public function search(string $term, Authenticatable $user, int $limit): array
    {
        return [new SearchHit("Gadget {$term}", 'https://example.test/gadget', [])];
    }
}
