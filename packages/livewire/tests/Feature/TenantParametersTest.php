<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Support\Facades\URL;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screens;
use Odden\Core\Screens\ScreenTenantParameters;
use Odden\Livewire\Tests\Fixtures\FixtureTenantParameters;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;

class TenantParametersTest extends RoutedTestCase
{
    protected string $routePrefix = '{workspace}/crm';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->singleton(ScreenTenantParameters::class, FixtureTenantParameters::class);
    }

    public function test_the_tenant_parameters_fill_the_route_prefix(): void
    {
        $tenant = User::factory()->create();

        $this->assertSame('/acme/crm/contacts', Screens::url('contacts.index', tenant: $tenant, absolute: false));
    }

    public function test_the_tenant_url_renders(): void
    {
        URL::defaults(['workspace' => 'acme']);
        Contact::factory()->create(['first_name' => 'Edsger', 'last_name' => 'Dijkstra']);

        $this->actingAs(User::factory()->create())
            ->get(Screens::url('contacts.index', tenant: User::factory()->create(), absolute: false))
            ->assertOk()
            ->assertSee('Edsger Dijkstra');
    }
}
