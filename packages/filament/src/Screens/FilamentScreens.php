<?php

declare(strict_types=1);

namespace Odden\Filament\Screens;

use Filament\Pages\Page;
use Filament\Resources\Resource;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\CrmListResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\LeadRoutingRuleResource;
use Odden\Filament\Resources\PipelineResource;
use Odden\Filament\Resources\PropertyDefinitionResource;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Resources\SalesEmailTemplateResource;
use Odden\Filament\Resources\SalesMeetingLinkResource;
use Odden\Filament\Resources\SalesPlaybookResource;
use Odden\Filament\Resources\SalesQuotaResource;
use Odden\Filament\Resources\SalesSequenceResource;
use Odden\Filament\Support\OddenPackages;

final class FilamentScreens
{
    public const SHELL = 'filament';

    /**
     * @param  class-string<resource>  $resource
     * @param  array<string, string>  $keys
     */
    public static function resource(ScreenRegistry $screens, string $resource, array $keys, ?string $panel = null): void
    {
        foreach ($keys as $page => $key) {
            $screens->draw($key, new ScreenDrawer(self::SHELL, $resource, $page, $panel));
        }
    }

    /**
     * @param  class-string<Page>  $page
     */
    public static function page(ScreenRegistry $screens, string $key, string $page, ?string $panel = null): void
    {
        $screens->draw($key, new ScreenDrawer(self::SHELL, $page, null, $panel));
    }

    public static function register(ScreenRegistry $screens): void
    {
        $screens->shell(self::SHELL, FilamentScreenShell::class, 0);

        self::resource($screens, ContactResource::class, self::keys('contacts', ['index', 'create', 'view', 'edit']));
        self::resource($screens, CompanyResource::class, self::keys('companies', ['index', 'create', 'view', 'edit']));
        self::resource($screens, CrmListResource::class, self::keys('lists', ['index', 'create', 'view', 'edit']));
        self::resource($screens, PropertyDefinitionResource::class, self::keys('custom-properties', ['index', 'create', 'edit']));
        self::page($screens, 'executive-overview', ExecutiveOverview::class);
        self::page($screens, 'data-quality', DataQuality::class);

        if (! OddenPackages::hasSales()) {
            return;
        }

        self::page($screens, 'sales-overview', SalesCockpit::class);
        self::resource($screens, DealResource::class, self::keys('deals', ['index', 'board', 'create', 'view', 'edit']));
        self::resource($screens, QuoteResource::class, self::keys('quotes', ['index', 'create', 'view', 'edit']));
        self::resource($screens, SalesQuotaResource::class, self::keys('quotas', ['index', 'create', 'edit']));
        self::resource($screens, SalesEmailTemplateResource::class, self::keys('sales-email-templates', ['index', 'create', 'edit']));
        self::resource($screens, SalesSequenceResource::class, self::keys('sequences', ['index', 'create', 'edit']));
        self::resource($screens, SalesPlaybookResource::class, self::keys('playbooks', ['index', 'create', 'edit']));
        self::resource($screens, SalesMeetingLinkResource::class, self::keys('meeting-links', ['index', 'create', 'edit']));
        self::resource($screens, LeadRoutingRuleResource::class, self::keys('lead-routing-rules', ['index', 'create', 'edit']));
        self::resource($screens, PipelineResource::class, self::keys('pipelines', ['index', 'create', 'edit']));
    }

    /**
     * @param  list<string>  $pages
     * @return array<string, string>
     */
    private static function keys(string $prefix, array $pages): array
    {
        return array_combine($pages, array_map(fn (string $page): string => "{$prefix}.{$page}", $pages));
    }
}
