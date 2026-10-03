<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Actions\CalculateRecipientOptimalSendTimeAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\CampaignType;

/**
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property string|null $preview_text
 * @property string $sender_name
 * @property string $sender_email
 * @property string|null $reply_to_email
 * @property int|null $template_id
 * @property int|null $list_id
 * @property int|null $crm_list_id
 * @property CampaignStatus $status
 * @property CampaignType $type
 * @property CarbonInterface|null $scheduled_at
 * @property bool $send_in_recipient_timezone
 * @property int $recipient_send_hour
 * @property bool $is_ab_test
 * @property string|null $variant_b_subject
 * @property int|null $variant_b_template_id
 * @property int $ab_test_sample_percentage
 * @property int $ab_test_duration_hours
 * @property string $ab_winning_metric
 * @property string|null $ab_winner_variant
 * @property CarbonInterface|null $ab_test_evaluated_at
 * @property CarbonInterface|null $sent_at
 * @property int $total_recipients
 * @property int $delivered_count
 * @property int $opens_count
 * @property int $unique_opens_count
 * @property int $clicks_count
 * @property int $unique_clicks_count
 * @property int $bounces_count
 * @property int $unsubscribes_count
 * @property array<string, mixed>|null $properties
 * @property float|null $budget
 * @property float|null $actual_cost
 * @property float|null $actual_spend
 * @property int|null $target_leads
 * @property float|null $target_pipeline
 * @property float|null $target_revenue
 * @property string|null $topic
 * @property int|null $topic_id
 * @property bool $utm_auto_tag
 * @property string|null $utm_campaign
 * @property bool $send_by_timezone
 * @property string|null $scheduled_local_time
 * @property bool $use_sto
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingTemplate|null $template
 * @property-read MarketingTemplate|null $variantBTemplate
 * @property-read CrmList|null $list
 * @property-read CrmList|null $crmList
 * @property-read Collection<int, CampaignRecipient> $recipients
 */
class Campaign extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'subject',
        'preview_text',
        'sender_name',
        'sender_email',
        'reply_to_email',
        'template_id',
        'list_id',
        'crm_list_id',
        'status',
        'type',
        'budget',
        'actual_cost',
        'actual_spend',
        'target_leads',
        'target_pipeline',
        'target_revenue',
        'topic',
        'topic_id',
        'scheduled_at',
        'send_in_recipient_timezone',
        'send_by_timezone',
        'scheduled_local_time',
        'use_sto',
        'recipient_send_hour',
        'utm_auto_tag',
        'utm_campaign',
        'is_ab_test',
        'variant_b_subject',
        'variant_b_template_id',
        'ab_test_sample_percentage',
        'ab_test_duration_hours',
        'ab_winning_metric',
        'ab_winner_variant',
        'ab_test_evaluated_at',
        'sent_at',
        'total_recipients',
        'delivered_count',
        'opens_count',
        'unique_opens_count',
        'clicks_count',
        'unique_clicks_count',
        'bounces_count',
        'unsubscribes_count',
        'properties',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.campaigns', 'odden_marketing_campaigns');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'type' => CampaignType::class,
            'budget' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'actual_spend' => 'decimal:2',
            'target_leads' => 'integer',
            'target_pipeline' => 'decimal:2',
            'target_revenue' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'send_in_recipient_timezone' => 'boolean',
            'send_by_timezone' => 'boolean',
            'use_sto' => 'boolean',
            'utm_auto_tag' => 'boolean',
            'topic_id' => 'integer',
            'recipient_send_hour' => 'integer',
            'sent_at' => 'datetime',
            'is_ab_test' => 'boolean',
            'ab_test_sample_percentage' => 'integer',
            'ab_test_duration_hours' => 'integer',
            'ab_test_evaluated_at' => 'datetime',
            'total_recipients' => 'integer',
            'delivered_count' => 'integer',
            'opens_count' => 'integer',
            'unique_opens_count' => 'integer',
            'clicks_count' => 'integer',
            'unique_clicks_count' => 'integer',
            'bounces_count' => 'integer',
            'unsubscribes_count' => 'integer',
            'properties' => 'array',
        ];
    }

    /**
     * Calculate the localized scheduled send timestamp for a recipient contact.
     */
    public function calculateScheduledTimeForContact(?Contact $contact = null, ?CarbonInterface $baseDate = null): CarbonInterface
    {
        return app(CalculateRecipientOptimalSendTimeAction::class)->execute($this, $contact, $baseDate);
    }

    /**
     * The campaign's UTM campaign value as a slug: its `utm_campaign` when set, else its name.
     * UTM auto-tagging writes this value into links, and attribution matches form submissions on it.
     */
    public function utmCampaignSlug(): string
    {
        return Str::slug(! empty($this->utm_campaign) ? (string) $this->utm_campaign : $this->name);
    }

    /**
     * Reusable marketing template.
     *
     * @return BelongsTo<MarketingTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'template_id');
    }

    /**
     * Secondary variant template for A/B split testing.
     *
     * @return BelongsTo<MarketingTemplate, $this>
     */
    public function variantBTemplate(): BelongsTo
    {
        return $this->belongsTo(MarketingTemplate::class, 'variant_b_template_id');
    }

    /**
     * Target audience list from getodden/crm-core.
     *
     * @return BelongsTo<CrmList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(CrmList::class, 'list_id');
    }

    /**
     * Target audience list from getodden/crm-core (via crm_list_id).
     *
     * @return BelongsTo<CrmList, $this>
     */
    public function crmList(): BelongsTo
    {
        return $this->belongsTo(CrmList::class, 'crm_list_id');
    }

    /**
     * Individual recipient tracking records.
     *
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }

    /**
     * Unique open rate percentage.
     */
    public function getOpenRateAttribute(): float
    {
        if ($this->delivered_count === 0) {
            return 0.0;
        }

        return round(($this->unique_opens_count / $this->delivered_count) * 100, 1);
    }

    /**
     * Unique click rate percentage (CTR).
     */
    public function getClickRateAttribute(): float
    {
        if ($this->delivered_count === 0) {
            return 0.0;
        }

        return round(($this->unique_clicks_count / $this->delivered_count) * 100, 1);
    }

    /**
     * Click to open rate percentage (CTOR).
     */
    public function getCtorAttribute(): float
    {
        if ($this->unique_opens_count === 0) {
            return 0.0;
        }

        return round(($this->unique_clicks_count / $this->unique_opens_count) * 100, 1);
    }

    /**
     * Forecasted leads attainment percentage.
     */
    public function getLeadsProgressPercentageAttribute(): float
    {
        if (empty($this->target_leads)) {
            return 0.0;
        }

        return round(($this->unique_clicks_count / $this->target_leads) * 100, 1);
    }

    /**
     * Associated subscription topic (if scoped to a specific communication type).
     *
     * @return BelongsTo<MarketingSubscriptionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(MarketingSubscriptionTopic::class, 'topic_id');
    }
}
