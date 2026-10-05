<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Panel;
use InvalidArgumentException;
use Odden\Filament\OddenPlugin;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Support\Modules;

class OddenPluginOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stand-ins for the separately installed packages, which register themselves the same way.
        Modules::flush();
        Modules::register('marketing', resources: ['Fake\\CampaignResource'], pages: ['Fake\\MarketingCockpit']);
        Modules::register('service', resources: ['Fake\\TicketResource'], pages: ['Fake\\ServiceCockpit']);
    }

    private function registeredPanel(OddenPlugin $plugin): Panel
    {
        $panel = Panel::make()->id('plugin-options-test')->path('plugin-options-test');
        $plugin->register($panel);

        return $panel;
    }

    public function test_registers_every_installed_module_by_default(): void
    {
        $panel = $this->registeredPanel(OddenPlugin::make());

        $this->assertContains(DealResource::class, $panel->getResources());
        $this->assertContains('Fake\\TicketResource', $panel->getResources());
        $this->assertContains('Fake\\CampaignResource', $panel->getResources());
        $this->assertContains(SalesCockpit::class, $panel->getPages());
    }

    public function test_modules_can_be_disabled(): void
    {
        $panel = $this->registeredPanel(OddenPlugin::make()->disableModules('marketing', 'service'));

        $this->assertContains(DealResource::class, $panel->getResources());
        $this->assertContains(SalesCockpit::class, $panel->getPages());
        $this->assertNotContains('Fake\\CampaignResource', $panel->getResources());
        $this->assertNotContains('Fake\\MarketingCockpit', $panel->getPages());
        $this->assertNotContains('Fake\\TicketResource', $panel->getResources());
        $this->assertNotContains('Fake\\ServiceCockpit', $panel->getPages());
    }

    public function test_a_package_that_is_not_installed_adds_nothing(): void
    {
        Modules::flush();

        $panel = $this->registeredPanel(OddenPlugin::make());

        $this->assertContains(DealResource::class, $panel->getResources());
        $this->assertNotContains('Fake\\TicketResource', $panel->getResources());
        $this->assertNotContains('Fake\\CampaignResource', $panel->getResources());
    }

    public function test_unknown_modules_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OddenPlugin::make()->disableModules('billing');
    }

    public function test_individual_resources_and_pages_can_be_left_out(): void
    {
        $panel = $this->registeredPanel(OddenPlugin::make()->except(DealResource::class, SalesCockpit::class));

        $this->assertNotContains(DealResource::class, $panel->getResources());
        $this->assertNotContains(SalesCockpit::class, $panel->getPages());
        $this->assertContains(ContactResource::class, $panel->getResources());
    }

    public function test_a_resource_can_be_replaced_with_a_subclass(): void
    {
        $panel = $this->registeredPanel(OddenPlugin::make()->replace(ContactResource::class, CustomContactResource::class));

        $this->assertContains(CustomContactResource::class, $panel->getResources());
        $this->assertNotContains(ContactResource::class, $panel->getResources());
    }
}

class CustomContactResource extends ContactResource {}
