<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Enums\DealOutcome;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Services\EloquentDealGateway;

class DealGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_binds_the_deal_gateway(): void
    {
        $this->assertInstanceOf(EloquentDealGateway::class, app(DealGateway::class));
    }

    public function test_creates_an_open_deal_in_the_first_stage_and_associates_the_contact(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $contact = Contact::factory()->create();

        $snapshot = app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0, $pipeline->id);

        $this->assertNotNull($snapshot);
        $this->assertSame(DealOutcome::Open, $snapshot->status);
        $this->assertSame($pipeline->stages()->orderBy('sort_order')->firstOrFail()->id, $snapshot->stageId);
        $this->assertSame(5000.0, $snapshot->amount);
        $this->assertTrue($contact->isAssociatedWith(Deal::query()->findOrFail($snapshot->id), 'primary'));
    }

    public function test_returns_null_without_a_pipeline(): void
    {
        $contact = Contact::factory()->create();

        $this->assertNull(app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0));
        $this->assertNull(app(DealGateway::class)->createOpenDeal($contact, 'Acme', 5000.0, 999));
    }

    public function test_find_many_returns_snapshots(): void
    {
        $deal = Deal::factory()->create();

        $found = app(DealGateway::class)->findMany([$deal->id]);

        $this->assertCount(1, $found);
        $this->assertSame($deal->id, $found->firstOrFail()->id);
    }
}
