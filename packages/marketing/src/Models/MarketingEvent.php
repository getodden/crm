<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Marketing\Support\ContactToken;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $event_type
 * @property string $status
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property string $timezone
 * @property string|null $virtual_meeting_url
 * @property string|null $location
 * @property int|null $capacity
 * @property int $registrations_count
 * @property int $attendees_count
 * @property bool $is_published
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, MarketingEventRegistration> $registrations
 * @property-read Collection<int, Contact> $contacts
 */
class MarketingEvent extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'description',
        'event_type',
        'status',
        'starts_at',
        'ends_at',
        'timezone',
        'virtual_meeting_url',
        'location',
        'capacity',
        'registrations_count',
        'attendees_count',
        'is_published',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.events', 'odden_marketing_events');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'registrations_count' => 'integer',
            'attendees_count' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (empty($event->slug)) {
                $event->slug = Str::slug($event->title);
            }
        });
    }

    /**
     * Event registrations.
     *
     * @return HasMany<MarketingEventRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(MarketingEventRegistration::class, 'event_id');
    }

    /**
     * Registered contacts.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            config('odden-marketing.tables.event_registrations', 'odden_marketing_event_registrations'),
            'event_id',
            'contact_id'
        )->withTimestamps()->withPivot(['status', 'registered_at', 'attended_at']);
    }

    /**
     * Calculate live attendance rate percentage.
     */
    public function attendanceRate(): float
    {
        if ($this->registrations_count === 0) {
            return 0.0;
        }

        return round(($this->attendees_count / $this->registrations_count) * 100, 1);
    }

    /**
     * Whether new registrations are accepted: the event is scheduled or live.
     */
    public function acceptsRegistrations(): bool
    {
        return in_array($this->status, ['scheduled', 'live'], true);
    }

    /**
     * Check if the event reached capacity.
     */
    public function isFull(): bool
    {
        return $this->capacity !== null && $this->registrations_count >= $this->capacity;
    }

    /**
     * Signed token identifying a contact for this event's in-email AMP RSVP form.
     */
    public function rsvpTokenFor(Contact $contact): string
    {
        return ContactToken::make($contact, ContactToken::forEvent($this->id));
    }
}
