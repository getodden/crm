<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Pages\ServiceAnalytics;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;

class ServiceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_service_analytics_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/service-analytics');

        $response->assertSuccessful();
        $response->assertSee('Support Operations & SLA Analytics');
        $response->assertSee('Service Performance Overview');
        $response->assertSee('Total Inbound');
        $response->assertSee('Avg First Response');
        $response->assertSee('Avg Resolution Time');
        $response->assertSee('SLA Compliance');
        $response->assertSee('Customer CSAT');
        $response->assertSee('Tickets by Channel');
        $response->assertSee('Tickets by Priority');
        $response->assertSee('Support Agent Performance Leaderboard');
    }

    public function test_service_analytics_computes_metrics_accurately(): void
    {
        $user = User::factory()->create(['name' => 'Agent Scully']);

        Ticket::create([
            'subject' => 'Issue A',
            'status' => TicketStatus::Resolved,
            'priority' => TicketPriority::Urgent,
            'source' => TicketSource::Email,
            'owner_id' => $user->id,
            'first_responded_at' => now()->subMinutes(15),
            'resolved_at' => now()->subMinutes(5),
            'csat_rating' => 5,
        ]);

        Ticket::create([
            'subject' => 'Issue B',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'owner_id' => $user->id,
            'first_responded_at' => now()->subMinutes(30),
            'resolved_at' => null,
            'csat_rating' => null,
        ]);

        $component = Livewire::actingAs($user)
            ->test(ServiceAnalytics::class)
            ->call('setDateRange', '30_days')
            ->assertSet('dateRange', '30_days')
            ->assertSuccessful();

        $stats = $component->instance()->summaryStats;

        $this->assertSame(2, $stats['total_tickets']);
        $this->assertSame(1, $stats['resolved_tickets']);
        $this->assertSame(50.0, $stats['resolution_rate']);
        $this->assertSame(22.5, $stats['avg_frt_minutes']);
        $this->assertSame(5.0, $stats['csat_average']);
        $this->assertSame(1, $stats['csat_total_ratings']);
        $this->assertSame(100.0, $stats['sla_compliance_rate']);
        $this->assertSame(0, $stats['sla_breaches_total']);
    }
}
