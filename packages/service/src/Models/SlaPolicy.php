<?php

declare(strict_types=1);

namespace Odden\Service\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Odden\Service\Enums\TicketPriority;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $only_business_hours
 * @property string $business_hours_start
 * @property string $business_hours_end
 * @property list<int>|null $business_days
 * @property list<string>|null $holidays
 * @property string $timezone
 * @property int $urgent_first_response_minutes
 * @property int $urgent_resolution_minutes
 * @property int $high_first_response_minutes
 * @property int $high_resolution_minutes
 * @property int $medium_first_response_minutes
 * @property int $medium_resolution_minutes
 * @property int $low_first_response_minutes
 * @property int $low_resolution_minutes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, Ticket> $tickets
 */
class SlaPolicy extends Model
{
    /** The most days calculateDueTime will step through before it gives up and counts plain minutes. */
    private const int MAX_SCHEDULE_DAYS = 800;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'is_default',
        'is_active',
        'only_business_hours',
        'business_hours_start',
        'business_hours_end',
        'business_days',
        'holidays',
        'timezone',
        'urgent_first_response_minutes',
        'urgent_resolution_minutes',
        'high_first_response_minutes',
        'high_resolution_minutes',
        'medium_first_response_minutes',
        'medium_resolution_minutes',
        'low_first_response_minutes',
        'low_resolution_minutes',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-service.tables.sla_policies', 'odden_service_sla_policies');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'only_business_hours' => 'boolean',
            'business_days' => 'array',
            'holidays' => 'array',
            'urgent_first_response_minutes' => 'integer',
            'urgent_resolution_minutes' => 'integer',
            'high_first_response_minutes' => 'integer',
            'high_resolution_minutes' => 'integer',
            'medium_first_response_minutes' => 'integer',
            'medium_resolution_minutes' => 'integer',
            'low_first_response_minutes' => 'integer',
            'low_resolution_minutes' => 'integer',
        ];
    }

    /**
     * Tickets adhering to this SLA.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'sla_policy_id');
    }

    /**
     * Get the first response target in minutes for a given priority.
     */
    public function getFirstResponseMinutesFor(TicketPriority $priority): int
    {
        return match ($priority) {
            TicketPriority::Urgent => $this->urgent_first_response_minutes,
            TicketPriority::High => $this->high_first_response_minutes,
            TicketPriority::Medium => $this->medium_first_response_minutes,
            TicketPriority::Low => $this->low_first_response_minutes,
        };
    }

    /**
     * Get the resolution target in minutes for a given priority.
     */
    public function getResolutionMinutesFor(TicketPriority $priority): int
    {
        return match ($priority) {
            TicketPriority::Urgent => $this->urgent_resolution_minutes,
            TicketPriority::High => $this->high_resolution_minutes,
            TicketPriority::Medium => $this->medium_resolution_minutes,
            TicketPriority::Low => $this->low_resolution_minutes,
        };
    }

    /**
     * Calculate the deadline timestamp from a start time, respecting business operating hours and holidays if configured.
     */
    public function calculateDueTime(CarbonInterface $from, int $minutes): CarbonInterface
    {
        if (! $this->only_business_hours) {
            return $from->copy()->addMinutes($minutes);
        }

        $tz = $this->timezone ?: 'UTC';
        $current = Carbon::parse($from)->setTimezone($tz);
        $remainingMinutes = $minutes;

        $businessDays = $this->normalizedBusinessDays();
        $holidays = array_values(array_filter((array) ($this->holidays ?? []), is_string(...)));

        [[$startHour, $startMinute], [$endHour, $endMinute]] = $this->normalizedBusinessHours();

        // Holidays that cover every upcoming day, or any other bad schedule, must not hang ticket creation.
        $iterations = 0;

        while ($remainingMinutes > 0) {
            if (++$iterations > self::MAX_SCHEDULE_DAYS) {
                return $from->copy()->addMinutes($minutes);
            }

            $isBusinessDay = in_array($current->dayOfWeekIso, $businessDays, true);
            $isHoliday = in_array($current->format('Y-m-d'), $holidays, true);

            if (! $isBusinessDay || $isHoliday) {
                $current = $current->copy()->addDay()->setTime($startHour, $startMinute, 0);

                continue;
            }

            $dayStart = $current->copy()->setTime($startHour, $startMinute, 0);
            $dayEnd = $current->copy()->setTime($endHour, $endMinute, 0);

            if ($current->isBefore($dayStart)) {
                $current = $dayStart->copy();
            }

            if ($current->isAfter($dayEnd) || $current->equalTo($dayEnd)) {
                $current = $current->copy()->addDay()->setTime($startHour, $startMinute, 0);

                continue;
            }

            $minutesAvailableToday = (int) $current->diffInMinutes($dayEnd, false);
            if ($minutesAvailableToday <= 0) {
                $current = $current->copy()->addDay()->setTime($startHour, $startMinute, 0);

                continue;
            }

            if ($remainingMinutes <= $minutesAvailableToday) {
                $current = $current->copy()->addMinutes($remainingMinutes);
                $remainingMinutes = 0;
            } else {
                $remainingMinutes -= $minutesAvailableToday;
                $current = $current->copy()->addDay()->setTime($startHour, $startMinute, 0);
            }
        }

        return $current->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * The days (ISO 1-7) the clock runs, as integers: form state arrives as strings, and an empty or invalid
     * list falls back to Monday to Friday so a due date can always be reached.
     *
     * @return list<int>
     */
    private function normalizedBusinessDays(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($this->business_days ?? [])),
            fn (int $day): bool => $day >= 1 && $day <= 7,
        )));

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    /**
     * The daily start and end as [hour, minute] pairs. A value that is not HH:MM, or an end that is not after the
     * start (including overnight hours, which are not supported), falls back to 09:00 to 17:00.
     *
     * @return array{array{int, int}, array{int, int}}
     */
    private function normalizedBusinessHours(): array
    {
        $start = self::parseClock($this->business_hours_start);
        $end = self::parseClock($this->business_hours_end);

        if ($start === null || $end === null || ($end[0] * 60 + $end[1]) <= ($start[0] * 60 + $start[1])) {
            return [[9, 0], [17, 0]];
        }

        return [$start, $end];
    }

    /**
     * @return array{int, int}|null
     */
    private static function parseClock(?string $value): ?array
    {
        if ($value === null || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] <= 23 && (int) $m[2] <= 59 ? [(int) $m[1], (int) $m[2]] : null;
    }

    /**
     * Default standard SLA configuration preset.
     *
     * @return array<string, mixed>
     */
    public static function defaultPreset(): array
    {
        return [
            'name' => 'Standard Customer Support SLA',
            'description' => 'Default tier SLA with 1h urgent response and 4h high priority response.',
            'is_default' => true,
            'is_active' => true,
            'urgent_first_response_minutes' => 60,
            'urgent_resolution_minutes' => 240,
            'high_first_response_minutes' => 120,
            'high_resolution_minutes' => 480,
            'medium_first_response_minutes' => 240,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 480,
            'low_resolution_minutes' => 2880,
        ];
    }
}
