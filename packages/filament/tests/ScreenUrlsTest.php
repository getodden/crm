<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Exceptions\ScreenNotAvailableException;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Filament\OddenPlugin;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Screens\FilamentScreens;
use Odden\Filament\Support\Modules;
use Odden\Filament\Tests\Fixtures\GlobalScreensServiceProvider;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ScreenUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TenantedScreensPanelProvider::class, LocallyReplacedScreensPanelProvider::class, GlobalScreensServiceProvider::class];
    }

    protected function tearDown(): void
    {
        GlobalScreensServiceProvider::reset();

        parent::tearDown();
    }

    protected function replaceContactsGlobally(): void
    {
        GlobalScreensServiceProvider::$replacements = [ContactResource::class => OtherContactResource::class];
    }

    protected function exceptContactsGlobally(): void
    {
        GlobalScreensServiceProvider::$exceptions = ['contacts.*'];
    }

    protected function exceptTheContactListGlobally(): void
    {
        GlobalScreensServiceProvider::$exceptions = ['contacts.index'];
    }

    protected function drawNamedPanelKeys(): void
    {
        GlobalScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(
                Screen::page('admin-contacts', 'Admin contacts'),
                Screen::page('admin-deals', 'Admin deals', module: 'sales'),
            );
            $screens->draw('admin-contacts', new ScreenDrawer(FilamentScreens::SHELL, ContactResource::class, 'index', 'admin'));
            $screens->draw('admin-deals', new ScreenDrawer(FilamentScreens::SHELL, DealResource::class, 'index', 'admin'));
        };
    }

    protected function disableSalesGlobally(): void
    {
        GlobalScreensServiceProvider::$disabledModules = ['sales'];
    }

    public function test_resource_urls_equal_filaments_own(): void
    {
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create();

        $this->assertSame(ContactResource::getUrl('index'), Screens::url('contacts.index'));
        $this->assertSame(ContactResource::getUrl('create'), Screens::url('contacts.create'));
        $this->assertSame(ContactResource::getUrl('view', ['record' => $contact]), Screens::url('contacts.view', $contact));
        $this->assertSame(ContactResource::getUrl('edit', ['record' => $contact]), Screens::url('contacts.edit', $contact));
        $this->assertSame(DealResource::getUrl('board'), Screens::url('deals.board'));
        $this->assertSame(DealResource::getUrl('view', ['record' => $deal]), Screens::url('deals.view', $deal));
    }

    public function test_a_record_can_be_given_as_a_key(): void
    {
        $contact = Contact::factory()->create();

        $this->assertSame(ContactResource::getUrl('view', ['record' => $contact->getKey()]), Screens::url('contacts.view', $contact->getKey()));
    }

    public function test_page_urls_equal_filaments_own(): void
    {
        $this->assertSame(DataQuality::getUrl(), Screens::url('data-quality'));
        $this->assertSame(SalesCockpit::getUrl(), Screens::url('sales-overview'));
    }

    public function test_parameters_become_the_query_string(): void
    {
        $contact = Contact::factory()->create();

        $this->assertSame(ContactResource::getUrl('index', ['tab' => 'mine']), Screens::url('contacts.index', parameters: ['tab' => 'mine']));
        $this->assertSame(ContactResource::getUrl('view', ['record' => $contact, 'tab' => 'notes']), Screens::url('contacts.view', $contact, ['tab' => 'notes']));
        $this->assertSame(DataQuality::getUrl(['view' => 'owners']), Screens::url('data-quality', parameters: ['view' => 'owners']));
        $this->assertStringContainsString('tab=mine', Screens::url('contacts.index', parameters: ['tab' => 'mine']));
    }

    public function test_relative_urls_are_honoured(): void
    {
        $this->assertSame(ContactResource::getUrl('index', isAbsolute: false), Screens::url('contacts.index', absolute: false));
    }

    public function test_a_tenant_is_ignored_when_the_panel_has_no_tenancy(): void
    {
        $tenant = User::factory()->create();

        $this->assertSame(ContactResource::getUrl('index'), Screens::url('contacts.index', tenant: $tenant));
    }

    public function test_a_tenant_is_used_when_the_current_panel_has_tenancy(): void
    {
        $tenant = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('tenanted'));

        $url = Screens::url('contacts.index', tenant: $tenant);

        $this->assertSame(ContactResource::getUrl('index', tenant: $tenant), $url);
        $this->assertStringContainsString("/tenanted/{$tenant->getRouteKey()}/", $url);
    }

    public function test_a_panel_local_replacement_gives_the_replacements_urls(): void
    {
        $contact = Contact::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('local'));

        $this->assertSame(PeopleContactResource::getUrl('index'), Screens::url('contacts.index'));
        $this->assertSame(PeopleContactResource::getUrl('view', ['record' => $contact]), Screens::url('contacts.view', $contact));
        $this->assertStringContainsString('/local/people', Screens::url('contacts.index'));
        $this->assertTrue(Screens::available('contacts.index'));
    }

    public function test_a_key_excepted_on_a_panel_is_not_available_there(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('local'));

        $this->assertFalse(Screens::available('data-quality'));
        $this->expectException(ScreenNotAvailableException::class);

        Screens::url('data-quality');
    }

    #[DefineEnvironment('replaceContactsGlobally')]
    public function test_a_global_replacement_is_registered_on_the_panel_and_used_for_urls(): void
    {
        $contact = Contact::factory()->create();
        $resources = Filament::getPanel('admin')->getResources();

        $this->assertContains(OtherContactResource::class, $resources);
        $this->assertNotContains(ContactResource::class, $resources);
        $this->assertSame(OtherContactResource::getUrl('view', ['record' => $contact]), Screens::url('contacts.view', $contact));
        $this->assertStringContainsString('/admin/others/', Screens::url('contacts.view', $contact));
    }

    #[DefineEnvironment('replaceContactsGlobally')]
    public function test_a_panel_local_replacement_wins_over_a_global_one(): void
    {
        $this->assertContains(PeopleContactResource::class, Filament::getPanel('local')->getResources());
        $this->assertNotContains(OtherContactResource::class, Filament::getPanel('local')->getResources());

        Filament::setCurrentPanel(Filament::getPanel('local'));

        $this->assertSame(PeopleContactResource::getUrl('index'), Screens::url('contacts.index'));
    }

    #[DefineEnvironment('exceptContactsGlobally')]
    public function test_a_resource_whose_every_key_is_excepted_globally_is_not_registered(): void
    {
        $this->assertNotContains(ContactResource::class, Filament::getPanel('admin')->getResources());
        $this->assertContains(DealResource::class, Filament::getPanel('admin')->getResources());
        $this->assertFalse(Screens::available('contacts.view'));
        $this->assertTrue(Screens::available('deals.view'));

        $this->expectException(ScreenNotAvailableException::class);

        Screens::url('contacts.index');
    }

    #[DefineEnvironment('exceptTheContactListGlobally')]
    public function test_excepting_one_key_keeps_the_resource_and_its_other_keys(): void
    {
        $contact = Contact::factory()->create();

        $this->assertContains(ContactResource::class, Filament::getPanel('admin')->getResources());
        $this->assertFalse(Screens::available('contacts.index'));
        $this->assertSame(ContactResource::getUrl('view', ['record' => $contact]), Screens::url('contacts.view', $contact));
    }

    #[DefineEnvironment('disableSalesGlobally')]
    public function test_a_module_disabled_globally_is_left_out_of_the_panel_and_the_keys(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $this->assertNotContains(DealResource::class, $panel->getResources());
        $this->assertNotContains(SalesCockpit::class, $panel->getPages());
        $this->assertContains(ContactResource::class, $panel->getResources());
        $this->assertFalse(Screens::available('deals.index'));
        $this->assertTrue(Screens::available('contacts.index'));
        $this->assertContains('sales', Modules::disabledOnCurrentPanel());

        Filament::setCurrentPanel(null);

        $this->assertSame(['sales'], Modules::disabledOnCurrentPanel());
    }

    #[DefineEnvironment('drawNamedPanelKeys')]
    public function test_a_key_drawn_on_a_named_panel_uses_that_panel_whatever_panel_is_current(): void
    {
        $expected = ContactResource::getUrl('index', panel: 'admin');

        Filament::setCurrentPanel(Filament::getPanel('local'));

        $this->assertSame($expected, Screens::url('admin-contacts'));
        $this->assertStringContainsString('/admin/contacts', $expected);
    }

    #[DefineEnvironment('drawNamedPanelKeys')]
    public function test_a_module_left_out_of_one_panel_makes_its_keys_unavailable_there_only(): void
    {
        $this->assertContains(DealResource::class, Filament::getPanel('admin')->getResources());
        $this->assertNotContains(DealResource::class, Filament::getPanel('local')->getResources());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(Screens::available('deals.index'));

        Filament::setCurrentPanel(Filament::getPanel('local'));
        $this->assertFalse(Screens::available('deals.index'));
        $this->assertTrue(Screens::available('admin-deals'));
        $this->assertSame(DealResource::getUrl('index', panel: 'admin'), Screens::url('admin-deals'));
    }

    public function test_nothing_is_disabled_when_nothing_is_registered_globally(): void
    {
        $this->assertSame([], Modules::disabledOnCurrentPanel());
        $this->assertContains(DealResource::class, Filament::getPanel('admin')->getResources());
    }
}

class PeopleContactResource extends ContactResource
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'people';
    }
}

class OtherContactResource extends ContactResource
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'others';
    }
}

class TenantedScreensPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('tenanted')->path('tenanted')->tenant(User::class, 'id')->plugins([OddenPlugin::make()]);
    }
}

class LocallyReplacedScreensPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('local')->path('local')->plugins([
            OddenPlugin::make()->replace(ContactResource::class, PeopleContactResource::class)->except(DataQuality::class)->disableModules('sales'),
        ]);
    }
}
