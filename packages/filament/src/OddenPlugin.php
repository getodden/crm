<?php

declare(strict_types=1);

namespace Odden\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use InvalidArgumentException;
use Odden\Filament\Pages\AbmCockpit;
use Odden\Filament\Pages\CampaignBenchmarking;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Pages\MarketingAttribution;
use Odden\Filament\Pages\MarketingCalendar;
use Odden\Filament\Pages\MarketingCockpit;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Pages\SenderDomainHealth;
use Odden\Filament\Pages\ServiceAnalytics;
use Odden\Filament\Pages\ServiceCockpit;
use Odden\Filament\Pages\UtmLinkBuilder;
use Odden\Filament\Resources\AdAudienceSyncResource;
use Odden\Filament\Resources\CampaignResource;
use Odden\Filament\Resources\CannedResponseResource;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\CrmListResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\KnowledgeArticleResource;
use Odden\Filament\Resources\LandingPageResource;
use Odden\Filament\Resources\LeadRoutingRuleResource;
use Odden\Filament\Resources\LeadScoringRuleResource;
use Odden\Filament\Resources\MarketingAssetResource;
use Odden\Filament\Resources\MarketingEventResource;
use Odden\Filament\Resources\MarketingFormResource;
use Odden\Filament\Resources\MarketingSubscriptionResource;
use Odden\Filament\Resources\MarketingTemplateResource;
use Odden\Filament\Resources\MarketingWorkflowResource;
use Odden\Filament\Resources\NpsSurveyResource;
use Odden\Filament\Resources\PipelineResource;
use Odden\Filament\Resources\PropertyDefinitionResource;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Resources\SalesEmailTemplateResource;
use Odden\Filament\Resources\SalesMeetingLinkResource;
use Odden\Filament\Resources\SalesPlaybookResource;
use Odden\Filament\Resources\SalesQuotaResource;
use Odden\Filament\Resources\SalesSequenceResource;
use Odden\Filament\Resources\SlaPolicyResource;
use Odden\Filament\Resources\TicketResource;
use Odden\Filament\Resources\TicketRoutingRuleResource;
use Odden\Marketing\Models\Campaign;
use Odden\Sales\Models\Deal;
use Odden\Service\Models\Ticket;

class OddenPlugin implements Plugin
{
    /** @var list<string> Modules whose resources and pages are left out. */
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

        if ($this->moduleEnabled('service', Ticket::class)) {
            $resources = array_merge($resources, [
                TicketResource::class,
                SlaPolicyResource::class,
                KnowledgeArticleResource::class,
                CannedResponseResource::class,
                TicketRoutingRuleResource::class,
            ]);
        }

        if ($this->moduleEnabled('marketing', Campaign::class)) {
            $resources = array_merge($resources, [
                CampaignResource::class,
                MarketingTemplateResource::class,
                MarketingFormResource::class,
                LandingPageResource::class,
                MarketingWorkflowResource::class,
                LeadScoringRuleResource::class,
                MarketingSubscriptionResource::class,
                NpsSurveyResource::class,
                MarketingAssetResource::class,
                MarketingEventResource::class,
                AdAudienceSyncResource::class,
            ]);
        }

        $panel->resources($this->customize($resources));

        $pages = [
            ExecutiveOverview::class,
            DataQuality::class,
        ];

        if ($this->moduleEnabled('sales', Deal::class)) {
            $pages[] = SalesCockpit::class;
        }

        if ($this->moduleEnabled('service', Ticket::class)) {
            $pages[] = ServiceCockpit::class;
            $pages[] = ServiceAnalytics::class;
        }

        if ($this->moduleEnabled('marketing', Campaign::class)) {
            $pages = array_merge($pages, [
                MarketingCockpit::class,
                AbmCockpit::class,
                MarketingAttribution::class,
                CampaignBenchmarking::class,
                MarketingCalendar::class,
                UtmLinkBuilder::class,
                SenderDomainHealth::class,
            ]);
        }

        $panel->pages($this->customize($pages));
    }

    /**
     * Leave out every resource and page of the given modules: 'sales', 'service' or 'marketing'.
     */
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
     * Leave out specific resources or pages by class name.
     *
     * @param  class-string  ...$classes
     */
    public function except(string ...$classes): static
    {
        $this->excluded = array_values(array_unique([...$this->excluded, ...$classes]));

        return $this;
    }

    /**
     * Register your own class (usually a subclass) in place of an Odden resource or page.
     *
     * @param  class-string  $original
     * @param  class-string  $replacement
     */
    public function replace(string $original, string $replacement): static
    {
        $this->replacements[$original] = $replacement;

        return $this;
    }

    protected function moduleEnabled(string $module, string $marker): bool
    {
        return class_exists($marker) && ! in_array($module, $this->disabledModules, true);
    }

    /**
     * Apply except() and replace() to a list of resource or page classes.
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    protected function customize(array $classes): array
    {
        $classes = array_values(array_filter($classes, fn (string $class): bool => ! in_array($class, $this->excluded, true)));

        return array_map(fn (string $class): string => $this->replacements[$class] ?? $class, $classes);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }
}
