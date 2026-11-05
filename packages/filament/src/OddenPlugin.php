<?php

declare(strict_types=1);

namespace Odden\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use InvalidArgumentException;
use Odden\Core\Screens\Screens;
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
use Odden\Filament\Screens\FilamentScreens;
use Odden\Filament\Support\Modules;
use Odden\Sales\Models\Deal;

class OddenPlugin implements Plugin
{
    /** @var list<string> */
    protected array $disabledModules = [];

    /** @var list<class-string> */
    protected array $excluded = [];

    /** @var array<class-string, class-string> */
    protected array $replacements = [];

    public function getId(): string
    {
        return 'odden';
    }

    public function register(Panel $panel): void
    {
        $resources = [
            ContactResource::class,
            CompanyResource::class,
            CrmListResource::class,
            PropertyDefinitionResource::class,
        ];

        if ($this->moduleEnabled('sales', Deal::class)) {
            $resources = array_merge($resources, [
                DealResource::class,
                PipelineResource::class,
                QuoteResource::class,
                SalesQuotaResource::class,
                SalesEmailTemplateResource::class,
                SalesSequenceResource::class,
                SalesPlaybookResource::class,
                SalesMeetingLinkResource::class,
                LeadRoutingRuleResource::class,
            ]);
        }

        $resources = array_merge($resources, Modules::resources($this->allDisabledModules()));

        $panel->resources($this->customize($resources));

        $pages = [
            ExecutiveOverview::class,
            DataQuality::class,
        ];

        if ($this->moduleEnabled('sales', Deal::class)) {
            $pages[] = SalesCockpit::class;
        }

        $pages = array_merge($pages, Modules::pages($this->allDisabledModules()));

        $panel->pages($this->customize($pages));
    }

    public function disableModules(string ...$modules): static
    {
        foreach ($modules as $module) {
            if (! in_array($module, ['sales', 'service', 'marketing'], true)) {
                throw new InvalidArgumentException("Unknown Odden module [{$module}]. Use 'sales', 'service' or 'marketing'.");
            }
        }

        $this->disabledModules = array_values(array_unique([...$this->disabledModules, ...$modules]));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDisabledModules(): array
    {
        return $this->disabledModules;
    }

    /**
     * @param  class-string  ...$classes
     */
    public function except(string ...$classes): static
    {
        $this->excluded = array_values(array_unique([...$this->excluded, ...$classes]));

        return $this;
    }

    /**
     * @param  class-string  $original
     * @param  class-string  $replacement
     */
    public function replace(string $original, string $replacement): static
    {
        $this->replacements[$original] = $replacement;

        return $this;
    }

    /**
     * @return array<class-string, class-string>
     */
    public function getReplacements(): array
    {
        return $this->replacements;
    }

    /**
     * @return list<class-string>
     */
    public function getExcluded(): array
    {
        return $this->excluded;
    }

    protected function moduleEnabled(string $module, string $marker): bool
    {
        return class_exists($marker) && ! in_array($module, $this->allDisabledModules(), true);
    }

    /**
     * @return list<string>
     */
    protected function allDisabledModules(): array
    {
        return array_values(array_unique([...$this->disabledModules, ...Screens::disabledModules()]));
    }

    /**
     * @param  class-string  $class
     */
    protected function exceptedGlobally(string $class): bool
    {
        $keys = Screens::keysDrawnBy(FilamentScreens::SHELL, $class);

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! Screens::isExcepted($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    protected function customize(array $classes): array
    {
        $classes = array_values(array_filter($classes, fn (string $class): bool => ! in_array($class, $this->excluded, true) && ! $this->exceptedGlobally($class)));

        return array_map(fn (string $class): string => $this->replacements[$class] ?? Screens::replacements()[$class] ?? $class, $classes);
    }

    public function boot(Panel $panel): void {}

    public static function make(): static
    {
        return app(static::class);
    }
}
