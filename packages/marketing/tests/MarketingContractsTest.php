<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Actions\SuggestSubjectLinesAction;
use Odden\Marketing\Actions\SyncAdAudienceAction;
use Odden\Marketing\Contracts\PublishesAdAudience;
use Odden\Marketing\Contracts\SuggestsSubjectLines;
use Odden\Marketing\Models\AdAudienceSync;

class MarketingContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_built_in_implementations_are_bound_by_default(): void
    {
        $this->assertInstanceOf(SuggestSubjectLinesAction::class, app(SuggestsSubjectLines::class));
        $this->assertInstanceOf(SyncAdAudienceAction::class, app(PublishesAdAudience::class));
    }

    public function test_the_built_in_subject_lines_have_the_shape_the_contract_promises(): void
    {
        $result = app(SuggestsSubjectLines::class)->execute('Autumn launch', 'urgent', 'founders');

        $this->assertSame(['suggestions', 'variant_b', 'preview_text', 'rationale'], array_keys($result));
        $this->assertNotEmpty($result['suggestions']);
        $this->assertContainsOnlyString($result['suggestions']);
    }

    public function test_an_application_can_replace_the_subject_line_writer_and_the_audience_publisher(): void
    {
        $this->app->bind(SuggestsSubjectLines::class, fn () => new class implements SuggestsSubjectLines
        {
            public function execute(string $topic, string $tone = 'engaging', ?string $audience = null): array
            {
                return ['suggestions' => ["Custom: {$topic}"], 'variant_b' => 'Custom B', 'preview_text' => 'Custom preview', 'rationale' => 'custom'];
            }
        });
        $this->app->bind(PublishesAdAudience::class, fn () => new class implements PublishesAdAudience
        {
            public function execute(AdAudienceSync $sync): array
            {
                return ['platform' => $sync->platform, 'records_synced' => 3, 'audience_id' => 'aud-1', 'message' => 'Uploaded 3 members to Meta.'];
            }
        });

        $this->assertSame(['Custom: Sale'], app(SuggestsSubjectLines::class)->execute('Sale')['suggestions']);

        $sync = AdAudienceSync::create(['name' => 'Meta: buyers', 'platform' => 'meta', 'list_id' => CrmList::create(['name' => 'Buyers', 'type' => 'static'])->id, 'is_active' => true]);
        $this->assertSame('Uploaded 3 members to Meta.', app(PublishesAdAudience::class)->execute($sync)['message']);
    }
}
