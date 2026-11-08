<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class SavePipelineStagesAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Pipeline $pipeline, array $attributes, ?PipelineStage $stage = null): PipelineStage
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($pipeline);

        return DB::transaction(function () use ($pipeline, $attributes, $stage): PipelineStage {
            if ($stage === null) {
                return $pipeline->stages()->create($attributes);
            }

            $stage->fill($attributes)->save();

            return $stage;
        });
    }
}
