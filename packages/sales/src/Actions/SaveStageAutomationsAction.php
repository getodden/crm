<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Actions\SyncChildRecordsAction;
use Odden\Sales\Models\PipelineStage;

class SaveStageAutomationsAction
{
    /**
     * @param  list<array<string, mixed>>  $automations
     * @param  list<int|string>|null  $loadedIds
     */
    public function execute(PipelineStage $stage, array $automations, ?array $loadedIds = null): PipelineStage
    {
        app(SyncChildRecordsAction::class)->execute($stage->automations(), $automations, null, $loadedIds);

        return $stage;
    }
}
