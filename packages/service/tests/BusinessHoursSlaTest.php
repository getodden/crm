<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;

class BusinessHoursSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_hours_sla_within_same_day(): void
    {
        $policy = SlaPolicy::create([
            'name' => 'Mon-Fri 9-5 SLA',
            'is_default' => true,
            'is_active' => true,
            'only_business_hours' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '17:00',
            'business_days' => [1, 2, 3, 4, 5],
            'timezone' => 'UTC',
            'urgent_first_response_minutes' => 60,
            'urgent_resolution_minutes' => 240,
            'high_first_response_minutes' => 120,
            'high_resolution_minutes' => 480,
            'medium_first_response_minutes' => 240,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 480,
            'low_resolution_minutes' => 2880,
        ]);

        // Wednesday at 10:00 AM UTC -> 60 minutes -> Wednesday 11:00 AM UTC
        $start = Carbon::parse('2026-10-07 10:00:00', 'UTC'); // 2026-10-07 is Wednesday
        $due = $policy->calculateDueTime($start, 60);

        $this->assertSame('2026-10-07 11:00:00', $due->format('Y-m-d H:i:s'));
    }

    public function test_business_hours_sla_spans_overnight(): void
    {
        $policy = SlaPolicy::create([
            'name' => 'Mon-Fri 9-5 SLA',
            'is_default' => true,
            'is_active' => true,
            'only_business_hours' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '17:00',
            'business_days' => [1, 2, 3, 4, 5],
            'timezone' => 'UTC',
            'urgent_first_response_minutes' => 60,
            'urgent_resolution_minutes' => 240,
            'high_first_response_minutes' => 120,
            'high_resolution_minutes' => 480,
            'medium_first_response_minutes' => 240,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 480,
            'low_resolution_minutes' => 2880,
        ]);

        // Wednesday 4:30 PM (16:30) with 60 mins -> 30 mins today until 17:00, remaining 30 mins tomorrow starting 09:00 -> Thursday 09:30 AM
        $start = Carbon::parse('2026-10-07 16:30:00', 'UTC');
        $due = $policy->calculateDueTime($start, 60);

        $this->assertSame('2026-10-08 09:30:00', $due->format('Y-m-d H:i:s'));
    }

    public function test_business_hours_sla_spans_weekend_and_holidays(): void
    {
        $policy = SlaPolicy::create([
            'name' => 'Mon-Fri 9-5 SLA with Holidays',
            'is_default' => true,
            'is_active' => true,
            'only_business_hours' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '17:00',
            'business_days' => [1, 2, 3, 4, 5],
            'holidays' => ['2026-10-12'], // Monday is holiday
            'timezone' => 'UTC',
            'urgent_first_response_minutes' => 60,
            'urgent_resolution_minutes' => 240,
            'high_first_response_minutes' => 120,
            'high_resolution_minutes' => 480,
            'medium_first_response_minutes' => 240,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 480,
            'low_resolution_minutes' => 2880,
        ]);

        // Friday 2026-10-09 16:30 with 60 mins:
        // 30 mins Friday until 17:00.
        // Saturday 10-10 & Sunday 10-11 skipped (weekend).
        // Monday 10-12 skipped (holiday).
        // Tuesday 10-13 09:00 + remaining 30 mins -> Tuesday 10-13 09:30 AM.
        $start = Carbon::parse('2026-10-09 16:30:00', 'UTC');
        $due = $policy->calculateDueTime($start, 60);

        $this->assertSame('2026-10-13 09:30:00', $due->format('Y-m-d H:i:s'));
    }

    public function test_ticket_creation_automatically_applies_business_hours_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 16:30:00', 'UTC')); // Friday 4:30 PM

        $policy = SlaPolicy::create([
            'name' => 'Operating Hours SLA',
            'is_default' => true,
            'is_active' => true,
            'only_business_hours' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '17:00',
            'business_days' => [1, 2, 3, 4, 5],
            'timezone' => 'UTC',
            'urgent_first_response_minutes' => 60,
            'urgent_resolution_minutes' => 240,
            'high_first_response_minutes' => 120,
            'high_resolution_minutes' => 480,
            'medium_first_response_minutes' => 240,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 480,
            'low_resolution_minutes' => 2880,
        ]);

        $ticket = Ticket::create([
            'subject' => 'Weekend breach prevention',
            'priority' => TicketPriority::Urgent,
            'status' => TicketStatus::New,
        ]);

        $this->assertNotNull($ticket->first_response_due_at);
        // Friday 16:30 + 60 mins -> Mon 09:30
        $this->assertSame('2026-10-12 09:30:00', $ticket->first_response_due_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function businessPolicy(array $overrides = []): SlaPolicy
    {
        return SlaPolicy::create($overrides + [
            'name' => 'Business hours',
            'is_default' => false,
            'is_active' => true,
            'only_business_hours' => true,
            'business_hours_start' => '09:00',
            'business_hours_end' => '17:00',
            'business_days' => [1, 2, 3, 4, 5],
            'timezone' => 'UTC',
            'urgent_first_response_minutes' => 60,
            'urgent_resolution_minutes' => 240,
            'high_first_response_minutes' => 120,
            'high_resolution_minutes' => 480,
            'medium_first_response_minutes' => 240,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 480,
            'low_resolution_minutes' => 2880,
        ]);
    }

    public function test_an_empty_business_days_list_falls_back_to_weekdays_instead_of_looping_forever(): void
    {
        $policy = $this->businessPolicy(['business_days' => []]);

        // Saturday 10:00, 60 minutes -> Monday 10:00 (weekdays assumed)
        $due = $policy->calculateDueTime(Carbon::parse('2026-10-10 10:00:00', 'UTC'), 60);

        $this->assertSame('2026-10-12 10:00:00', $due->format('Y-m-d H:i:s'));
    }

    public function test_business_days_saved_as_strings_are_understood(): void
    {
        $policy = $this->businessPolicy(['business_days' => ['1', '2', '3', '4', '5']]);

        // Friday 16:30, 60 minutes -> Monday 09:30
        $due = $policy->calculateDueTime(Carbon::parse('2026-10-09 16:30:00', 'UTC'), 60);

        $this->assertSame('2026-10-12 09:30:00', $due->format('Y-m-d H:i:s'));
    }

    public function test_hours_that_end_before_they_start_or_are_not_times_use_nine_to_five(): void
    {
        foreach ([['22:00', '06:00'], ['9am', '5pm'], ['09:00', '09:00'], ['25:00', '26:00']] as [$start, $end]) {
            $policy = $this->businessPolicy(['business_hours_start' => $start, 'business_hours_end' => $end]);

            // Wednesday 16:30, 60 minutes -> Thursday 09:30
            $due = $policy->calculateDueTime(Carbon::parse('2026-10-07 16:30:00', 'UTC'), 60);

            $this->assertSame('2026-10-08 09:30:00', $due->format('Y-m-d H:i:s'), "{$start}-{$end}");
        }
    }

    public function test_holidays_covering_every_upcoming_day_fall_back_to_plain_minutes(): void
    {
        $start = Carbon::parse('2026-10-07 10:00:00', 'UTC');
        $holidays = [];
        for ($day = 0; $day < 1000; $day++) {
            $holidays[] = $start->copy()->addDays($day)->format('Y-m-d');
        }
        $policy = $this->businessPolicy(['holidays' => $holidays]);

        $due = $policy->calculateDueTime($start, 90);

        $this->assertSame('2026-10-07 11:30:00', $due->format('Y-m-d H:i:s'));
    }
}
