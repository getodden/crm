---
title: Attribution and closed-loop reporting
description: Attribute pipeline and revenue to campaigns, calculate closed-loop marketing ROI, and analyze multi-step conversion funnels.
---

Three actions report on how marketing turns into revenue. `GetCampaignAttributionAction` reports on a single campaign, `CalculateClosedLoopMetricsAction` reports on all marketing activity, and `AnalyzeConversionFunnelAction` counts how many people reach each step of a funnel you define. All of them run their queries when called; nothing is stored or scheduled.

Revenue figures come from deals in `getodden/crm-sales`. Without that package installed, the revenue and deal values are zero. A deal counts as influenced by marketing when it's [associated](../core/associations.md) with a contact that marketing reached.

## Attribution models

`Odden\Marketing\Enums\AttributionModel` selects how a deal's credit is split across the marketing touches that led to it:

| Case | Value |
| --- | --- |
| `FirstTouch` | `first_touch` |
| `LastTouch` | `last_touch` |
| `Linear` | `linear` |
| `UShaped` | `u_shaped` |
| `WShaped` | `w_shaped` |
| `TimeDecay` | `time_decay` |

`label()` returns a display name such as `U-Shaped Attribution (40/40/20 Weighting)`.

### Touches and weights

A **touch** is either a campaign recipient's first open or click (at the time of that first engagement), or a form submission whose `utm_campaign` matches a campaign (see [Campaign attribution](#campaign-attribution)). `Odden\Marketing\Services\AttributionCalculator` collects the touches of **all** the contacts associated with a deal, across every campaign, puts them in time order, and splits the deal's credit across them. The weights of a deal always add up to 1, so no model creates or loses revenue; it only decides which campaign gets it. A touch counts whenever it happened, before or after the deal closed.

| Model | Split |
| --- | --- |
| `FirstTouch` | 100% to the first touch |
| `LastTouch` | 100% to the last touch |
| `Linear` | Equal shares |
| `UShaped` | 40% first, 40% last, 20% shared by the touches in between (50/50 with two touches) |
| `WShaped` | 30% first, 30% the touch that converted the lead (the first form submission between the first and last touch, else the middle touch), 30% last, and 10% shared by the rest (if there are no others, the three share it equally; 50/50 with two touches) |
| `TimeDecay` | Each touch is worth half as much for every `odden-marketing.attribution.time_decay_half_life_days` (default 7, env `MARKETING_ATTRIBUTION_HALF_LIFE_DAYS`) before the deal's last touch, normalized to 1 |

With a single touch, every model gives it all of the credit. A campaign's credit for a deal is the sum of the weights of its touches.

## Campaign attribution

```php
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\AttributionModel;

$report = app(GetCampaignAttributionAction::class)->execute($campaign, AttributionModel::UShaped);

$report['won_revenue'];            // won deal amounts, unweighted
$report['attributed_won_revenue']; // this campaign's share of the won revenue under the model
$report['roi_percentage'];
```

`execute(Campaign $campaign, AttributionModel $model = AttributionModel::Linear): array` collects two groups of contacts:

- **Leads**: contacts with a [form submission](forms-and-landing-pages.md#what-happens-on-submission) whose `utm_campaign` matches the campaign's UTM slug, `Campaign::utmCampaignSlug()`: `Str::slug()` of its `utm_campaign` field, or of its name when that is empty. Both sides are compared as slugs, so `Spring  Sale 2026` matches a campaign whose `utm_campaign` is `spring-sale-2026`.
- **Engaged contacts**: the campaign's recipients who opened or clicked.

It then looks at every deal associated with any of these contacts, on either side of the association: `won` deals go into won revenue and `open` deals into pipeline, each counted once. The attributed values are the deal amounts times this campaign's credit for that deal (see above).

The same slug is what [UTM auto-tagging](campaigns.md) writes into the campaign's links, so a tracked link and the matching form submission always agree.

The returned array:

| Key | Meaning |
| --- | --- |
| `campaign_name`, `attribution_model` | Echoed inputs. |
| `leads_count` | Distinct lead contacts. |
| `engaged_contacts_count` | Distinct engaged recipients. |
| `deals_count` | Distinct associated deals, any status. |
| `budget` | The campaign's `budget`, or `null`. |
| `actual_cost` | `actual_spend`, falling back to `actual_cost`, else 0. |
| `pipeline_value`, `won_revenue` | Unweighted open and won deal amounts. |
| `attributed_pipeline_value`, `attributed_won_revenue` | This campaign's weighted share of the open and won amounts. |
| `net_profit` | `attributed_won_revenue − actual_cost`. |
| `roi_percentage` | `net_profit / actual_cost × 100`, or 0 without a cost. |
| `cost_per_lead` | `actual_cost / leads_count`, or 0 without leads. |

For example, a contact reached by three campaigns (touches 30, 15 and 1 days ago) with a $100,000 won deal gives the first and last campaign 40,000 each and the middle one 20,000 under `UShaped`. For a campaign with one lead whose $5,000 won deal has no other touches, `attributed_won_revenue` is 5000 under any model; with $1,000 `actual_spend`, `roi_percentage` is 400 and `cost_per_lead` 1000.

Budget fields such as `budget`, `actual_spend`, and `target_revenue` are set on the campaign; see [campaigns](campaigns.md).

## Closed-loop metrics

```php
use Odden\Marketing\Actions\CalculateClosedLoopMetricsAction;
use Odden\Marketing\Enums\AttributionModel;

$metrics = app(CalculateClosedLoopMetricsAction::class)->execute(AttributionModel::Linear);
```

`execute(?AttributionModel $model = null): array` looks at every contact that marketing reached: any campaign recipient (whether or not they engaged) and any contact with a form submission. Influenced deals are the deals associated with those contacts, in either direction.

Total marketing spend is the sum of all campaigns' `actual_spend`, or, if that sum is 0, the sum of `actual_cost`.

| Key | Meaning |
| --- | --- |
| `total_influenced_pipeline` | Sum of all influenced deal amounts, any status. |
| `total_closed_won_revenue` | Sum of won influenced deals. |
| `total_marketing_spend` | See above. |
| `blended_cac` | Spend / won deals. |
| `cost_per_lead` | Spend / marketing-reached contacts. |
| `marketing_roi_percentage` | `(won revenue − spend) / spend × 100`, one decimal. |
| `won_deals_count`, `open_deals_count` | Influenced deals by status. |
| `marketing_win_rate` | Won / (won + lost) × 100, one decimal. |
| `average_sales_cycle_days` | Average days from the first associated contact's `created_at` to the deal's `closed_at` (or `updated_at`), minimum 1 per deal. |
| `top_campaigns` | Up to five campaigns with delivered emails and influenced pipeline, sorted by won revenue. Each has `name`, `won_revenue`, `pipeline_influenced`, `spend`, `roi_percentage`, and, when you pass a model, `attributed_won_revenue` (the campaign's weighted share of the won revenue). |
| `attribution_model` | The model value, or `null`. |
| `attributed_closed_won_revenue`, `attributed_pipeline` | Without a model, the same as the totals. With one, the won revenue and pipeline of the deals that have at least one marketing touch (a deal's credit adds up to the whole deal whatever the model, so the models differ per campaign, not in this total). |
| `attributed_roi_percentage` | ROI using the attributed won revenue. |

When there are no marketing contacts or influenced deals, every value except the spend (and `cost_per_lead`, when there are contacts) is 0. `top_campaigns` finds deals associated with the campaign's contacts in either direction, like the totals.

## Conversion funnels

`Odden\Marketing\Actions\AnalyzeConversionFunnelAction` counts each step of a funnel within a date range:

```php
use Odden\Marketing\Actions\AnalyzeConversionFunnelAction;

$funnel = app(AnalyzeConversionFunnelAction::class)->execute(
    steps: [
        ['name' => 'Viewed pricing', 'type' => 'page_view', 'path' => '/pricing'],
        ['name' => 'Requested a demo', 'type' => 'form_submission', 'form_slug' => 'request-a-demo'],
        ['name' => 'Closed won', 'type' => 'deal_won'],
    ],
    startDate: now()->subDays(90),
);

foreach ($funnel['steps'] as $step) {
    echo "{$step['name']}: {$step['count']} ({$step['conversion_rate']}% of previous step)";
}
```

`execute(array $steps, ?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): array` defaults to the last 30 days (from the start of the day 30 days ago to the end of today). Step types and what they count:

| `type` | Counts | Filters |
| --- | --- | --- |
| `page_view` or `page_visit` | Distinct [visitor sessions](web-tracking.md#sessions-and-page-views) with a matching pageview | `path`, matched as a substring |
| `form_submission` | Form submissions | `form_id` or `form_slug` |
| `behavioral_event` | Distinct contacts with a [custom event](inbound-webhooks.md#custom-behavioral-events) | `event_name` |
| `campaign_click` | Distinct recipient emails that clicked | `campaign_id` |
| `contact_created` | Contacts created | none |
| `deal_created` | Deals created (0 without `getodden/crm-sales`) | none |
| `deal_won` | Deals won, by `closed_at` (0 without `getodden/crm-sales`) | none |

Each step is counted independently over the whole date range; the action doesn't follow individual people from one step to the next. An unknown type counts 0.

The result has `steps` (each with `index`, `name`, `type`, `count`, `conversion_rate` from the previous step, `dropoff_count`, `dropoff_rate`, and `overall_conversion_rate` from the first step), `total_top_of_funnel`, `total_bottom_of_funnel`, `overall_funnel_conversion_rate`, and `time_window_days`. A step that follows a step with a count of 0 shows a 100% conversion rate.
