<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;

class SignedQuoteEditingTest extends TestCase
{
    use RefreshDatabase;

    private function quote(QuoteStatus $status): Quote
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);

        return Quote::factory()->create(['deal_id' => $deal->id, 'status' => $status]);
    }

    public function test_a_signed_quote_cannot_be_edited_but_an_open_one_can(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertTrue(QuoteResource::canEdit($this->quote(QuoteStatus::Sent)));
        $this->assertFalse(QuoteResource::canEdit($this->quote(QuoteStatus::Accepted)));
    }

    public function test_the_edit_page_of_a_signed_quote_is_forbidden(): void
    {
        $user = User::factory()->create();
        $signed = $this->quote(QuoteStatus::Accepted);
        $open = $this->quote(QuoteStatus::Sent);

        $this->actingAs($user)->get("/admin/quotes/{$open->id}/edit")->assertSuccessful();
        $this->actingAs($user)->get("/admin/quotes/{$signed->id}/edit")->assertForbidden();
    }
}
