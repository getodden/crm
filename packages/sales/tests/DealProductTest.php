<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Models\Pipeline;

class DealProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_products_and_auto_sync_deal_amount(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
            'amount' => 0.00,
        ]);

        // Product 1: 2 x $500 = $1,000
        DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Professional License',
            'sku' => 'PRO-01',
            'unit_price' => 500.00,
            'quantity' => 2,
            'discount_percent' => 0.00,
        ]);

        $this->assertEquals(1000.00, $deal->fresh()->amount);

        // Product 2: 1 x $200 with 10% discount = $180
        $product2 = DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Onboarding Package',
            'sku' => 'ONB-01',
            'unit_price' => 200.00,
            'quantity' => 1,
            'discount_percent' => 10.00,
        ]);

        $this->assertEquals(180.00, $product2->fresh()->total_price);
        $this->assertEquals(1180.00, $deal->fresh()->amount);

        // Delete product 2 -> deal amount updates to $1000
        $product2->delete();
        $this->assertEquals(1000.00, $deal->fresh()->amount);
    }

    public function test_discount_cannot_make_total_price_negative(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
        ]);

        $product = DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Free Trial Extra',
            'unit_price' => 100.00,
            'quantity' => 1,
            'discount_percent' => 120.00, // Excess discount
        ]);

        $this->assertEquals(0.00, $product->fresh()->total_price);
    }

    public function test_missing_quantity_defaults_to_one_when_calculating_total_price(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages()->firstOrFail()->id,
            'amount' => 0.00,
        ]);

        $product = DealProduct::create([
            'deal_id' => $deal->id,
            'name' => 'Default Quantity Item',
            'unit_price' => 250.00,
            'discount_percent' => 0.00,
        ]);

        $this->assertEquals(250.00, $product->total_price);
        $this->assertEquals(250.00, $product->fresh()->total_price);
        $this->assertEquals(250.00, $deal->fresh()->amount);
    }
}
