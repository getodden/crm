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
        $rep1 = User::factory()->create(['name' => 'Alice']);
        $rep2 = User::factory()->create(['name' => 'Bob']);

        LeadRoutingRule::query()->create([
            'name' => 'Inbound Round Robin',
            'strategy' => LeadRoutingStrategy::RoundRobin,
            'assigned_user_ids' => [$rep1->id, $rep2->id],
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

    public function test_territory_strategy_routes_matching_leads_to_the_territory_owner(): void
    {
        $owner = User::factory()->create(['name' => 'Territory Owner']);
        $other = User::factory()->create(['name' => 'Other Rep']);

        LeadRoutingRule::query()->create([
            'name' => 'EMEA Territory',
            'strategy' => LeadRoutingStrategy::Territory,
            'criteria' => ['timezone' => 'Europe/Berlin'],
            'assigned_user_ids' => [$owner->id, $other->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $first = Contact::factory()->create(['lead_status' => LeadStatus::New, 'timezone' => 'Europe/Berlin']);
        $second = Contact::factory()->create(['lead_status' => LeadStatus::New, 'timezone' => 'Europe/Berlin']);
        $outside = Contact::factory()->create(['lead_status' => LeadStatus::New, 'timezone' => 'America/New_York']);

        $action = new RouteLeadAction;

        $this->assertSame($owner->id, $action->execute($first)['assigned_user_id']);
        $this->assertSame($owner->id, $action->execute($second)['assigned_user_id']);
        $this->assertNull($action->execute($outside));
    }

    public function test_territory_rule_without_criteria_never_applies(): void
    {
        $owner = User::factory()->create();

        LeadRoutingRule::query()->create([
            'name' => 'Misconfigured Territory',
            'strategy' => LeadRoutingStrategy::Territory,
            'assigned_user_ids' => [$owner->id],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);

        $this->assertNull((new RouteLeadAction)->execute($contact));
    }
}
