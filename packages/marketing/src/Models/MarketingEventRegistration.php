<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Models\Contact;

/**
 * @property int $id
 * @property int $event_id
 * @property int $contact_id
 * @property string $status
 * @property CarbonInterface $registered_at
 * @property CarbonInterface|null $attended_at
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read MarketingEvent $event
 * @property-read Contact $contact
 */
class MarketingEventRegistration extends Model
{
    /** @var list<string> The statuses a registration can have. */
    public const STATUSES = ['registered', 'attended', 'no_show', 'cancelled'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'contact_id',
        'status',
        'registered_at',
        'attended_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.event_registrations', 'odden_marketing_event_registrations');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'attended_at' => 'datetime',
        ];
    }

    /**
     * Associated event.
     *
     * @return BelongsTo<MarketingEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(MarketingEvent::class, 'event_id');
    }

    /**
     * Registered contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
