<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\RecipientStatus;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int|null $contact_id
 * @property string $email
 * @property RecipientStatus $status
 * @property string|null $variant
 * @property string $tracking_token
 * @property string $unsubscribe_token
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $clicked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Campaign $campaign
 * @property-read Contact|null $contact
 */
class CampaignRecipient extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'email',
        'status',
        'variant',
        'tracking_token',
        'unsubscribe_token',
        'scheduled_send_at',
        'sent_at',
        'opened_at',
        'clicked_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.recipients', 'odden_marketing_campaign_recipients');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'scheduled_send_at' => 'datetime',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $recipient): void {
            if (empty($recipient->tracking_token)) {
                $recipient->tracking_token = Str::random(40);
            }
            if (empty($recipient->unsubscribe_token)) {
                $recipient->unsubscribe_token = Str::random(40);
            }
        });
    }

    /**
     * Associated broadcast campaign.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /**
     * Associated contact record.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * The status after an engagement event: it only moves forward (pending, sent, opened,
     * clicked), and a final status (bounced, unsubscribed, suppressed) is never overwritten.
     */
    protected function statusAfter(RecipientStatus $engagement): RecipientStatus
    {
        $progression = [RecipientStatus::Pending, RecipientStatus::Sent, RecipientStatus::Opened, RecipientStatus::Clicked];

        $current = $this->status;
        if (! in_array($current, $progression, true)) {
            return $current;
        }

        return array_search($engagement, $progression, true) > array_search($current, $progression, true) ? $engagement : $current;
    }

    /**
     * Record an email open event.
     */
    public function recordOpen(): void
    {
        $isFirstOpen = $this->opened_at === null;

        $this->update([
            'status' => $this->statusAfter(RecipientStatus::Opened),
            'opened_at' => $this->opened_at ?? now(),
        ]);

        $this->campaign->increment('opens_count');

        if ($isFirstOpen) {
            $this->campaign->increment('unique_opens_count');
        }
    }

    /**
     * Record an email link click event.
     */
    public function recordClick(): void
    {
        $isFirstClick = $this->clicked_at === null;

        $this->update([
            'status' => $this->statusAfter(RecipientStatus::Clicked),
            'clicked_at' => $this->clicked_at ?? now(),
        ]);

        $this->campaign->increment('clicks_count');

        if ($isFirstClick) {
            $this->campaign->increment('unique_clicks_count');
        }
    }

    /**
     * Get the tracking pixel URL for this recipient.
     */
    public function getTrackingPixelUrl(): string
    {
        return route('odden.marketing.track.open', $this->tracking_token);
    }

    /**
     * Get the click tracking redirect URL for a destination link.
     */
    public function getClickRedirectUrl(string $destinationUrl): string
    {
        return route('odden.marketing.track.click', [
            'token' => $this->tracking_token,
            'url' => $destinationUrl,
            'sig' => self::clickSignature((string) $this->tracking_token, $destinationUrl),
        ]);
    }

    /**
     * HMAC binding a tracking token to one destination, so the click redirect
     * cannot be used as an open redirect to arbitrary URLs.
     */
    public static function clickSignature(string $token, string $destinationUrl, ?string $key = null): string
    {
        // JSON-encode the parts so no token/URL combination can produce the same signed string as another.
        return hash_hmac('sha256', (string) json_encode(['odden-click', $token, $destinationUrl]), $key ?? (string) config('app.key'));
    }

    /**
     * Links stay valid after an APP_KEY rotation while the old key is listed in APP_PREVIOUS_KEYS.
     */
    public static function hasValidClickSignature(string $token, mixed $destinationUrl, mixed $signature): bool
    {
        if (! is_string($destinationUrl) || ! is_string($signature)) {
            return false;
        }

        foreach ([config('app.key'), ...(array) config('app.previous_keys', [])] as $key) {
            if (is_string($key) && $key !== '' && hash_equals(self::clickSignature($token, $destinationUrl, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the 1-click unsubscribe URL.
     */
    public function getUnsubscribeUrl(): string
    {
        return route('odden.marketing.unsubscribe.show', $this->unsubscribe_token);
    }

    /**
     * URL for the List-Unsubscribe header. A GET shows the confirmation page; an
     * RFC 8058 one-click POST ("List-Unsubscribe=One-Click") unsubscribes at once.
     * The unsubscribe token in the URL is the credential, so the POST is CSRF-exempt.
     */
    public function getOneClickUnsubscribeUrl(): string
    {
        return route('odden.marketing.unsubscribe.process', $this->unsubscribe_token);
    }

    /**
     * Path that every unsubscribe link starts with (e.g. "/marketing/unsubscribe/"),
     * honoring any configured route prefix. Used to exempt these links from click
     * tracking and UTM tagging.
     */
    public static function unsubscribePathPrefix(): string
    {
        return Str::before(route('odden.marketing.unsubscribe.show', '__token__', false), '__token__');
    }
}
