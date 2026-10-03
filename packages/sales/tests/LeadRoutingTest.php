<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Contact;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Tests\Fixtures\User;

class LeadRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_route_leads_using_round_robin_strategy(): void
    {
        $this->markTestIncomplete('Round robin skips the first user; fixed by #37.');

        $rep1 = User::factory()->create(['name' => 'Alice']);
        $rep2 = User::factory()->create(['name' => 'Bob']);

        LeadRoutingRule::query()->create([
            'name' => 'Inbound Round Robin',
            'strategy' => LeadRoutingStrategy::RoundRobin,
            'assigned_user_ids' => [$rep1->id, $rep2->id],
            'last_assigned_index' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $contact1 = Contact::factory()->create(['lead_status' => LeadStatus::New]);
        $contact2 = Contact::factory()->create(['lead_status' => LeadStatus::New]);
        $contact3 = Contact::factory()->create(['lead_status' => LeadStatus::New]);

        $action = new RouteLeadAction;

        // First lead goes to rep1 (index 0)
        $result1 = $action->execute($contact1);
        $this->assertNotNull($result1);
        $this->assertSame($rep1->id, $result1['assigned_user_id']);
        $this->assertSame($rep1->id, $contact1->fresh()->owner_id);

        // Second lead goes to rep2 (index 1)
        $result2 = $action->execute($contact2);
        $this->assertNotNull($result2);
        $this->assertSame($rep2->id, $result2['assigned_user_id']);
        $this->assertSame($rep2->id, $contact2->fresh()->owner_id);

        // Third lead wraps around to rep1
        $result3 = $action->execute($contact3);
        $this->assertNotNull($result3);
        $this->assertSame($rep1->id, $result3['assigned_user_id']);

        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => $contact1->getMorphClass(),
            'subject_id' => $contact1->id,
            'type' => ActivityType::Note->value,
            'title' => 'Lead Routed to Alice',
        ]);
    }
}
