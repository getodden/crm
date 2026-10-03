<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\LifecycleStageChanged;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Support\LifecycleStateMachine;

class TransitionLifecycleStageAction
{
    public function __construct(
        protected LifecycleStateMachine $stateMachine
    ) {}

    /**
     * Transition a CRM record's lifecycle stage, validating state machine guards and recording history.
     */
    public function execute(
        Model $record,
        LifecycleStage $toStage,
        string $source = 'manual',
        ?int $userId = null,
        bool $force = false
    ): LifecycleStageTransition {
        // Validate against state machine guards and progression rules
        $this->stateMachine->validateTransition($record, $toStage, $source, $force);

        $rawFromStage = $record->getAttribute('lifecycle_stage');
        $fromStage = null;

        if ($rawFromStage instanceof LifecycleStage) {
            $fromStage = $rawFromStage;
        } elseif (is_string($rawFromStage) && $rawFromStage !== '') {
            $fromStage = LifecycleStage::tryFrom($rawFromStage);
        }

        // Determine elapsed duration in the from_stage
        /** @var LifecycleStageTransition|null $lastTransition */
        $lastTransition = LifecycleStageTransition::query()
            ->where('record_type', $record->getMorphClass())
            ->where('record_id', $record->getKey())
            ->orderBy('transitioned_at', 'desc')
            ->first();

        $referenceTime = $lastTransition !== null
            ? $lastTransition->transitioned_at
            : $record->getAttribute('created_at');

        if ($referenceTime !== null && ! ($referenceTime instanceof CarbonInterface)) {
            $referenceTime = Carbon::parse($referenceTime);
        }
        $referenceTime ??= now();

        $durationSeconds = max(0, abs((int) now()->diffInSeconds($referenceTime)));

        // Update record lifecycle stage
        $record->setAttribute('lifecycle_stage', $toStage);

        // Update became_<stage>_at timestamp if currently null
        $becameColumn = "became_{$toStage->value}_at";
        if ($record->getAttribute($becameColumn) === null) {
            $record->setAttribute($becameColumn, now());
        }

        $record->save();

        /** @var int|null $teamId */
        $teamId = $record->getAttribute('team_id');

        /** @var LifecycleStageTransition $transition */
        $transition = LifecycleStageTransition::create([
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'duration_seconds' => $durationSeconds,
            'source' => $source,
            'user_id' => $userId,
            'team_id' => $teamId,
            'transitioned_at' => now(),
        ]);

        event(new LifecycleStageChanged($record, $transition));

        return $transition;
    }
}
