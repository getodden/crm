<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\ActivityLogged;
use Odden\Core\Models\Activity;

class LogActivityAction
{
    /**
     * Log an activity on any model.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Model $subject,
        ActivityType|string $type,
        string $title,
        ?string $body = null,
        array $metadata = [],
        ActivityStatus|string $status = ActivityStatus::Completed,
        ?CarbonInterface $dueAt = null,
        ?int $creatorId = null
    ): Activity {
        $activity = Activity::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type instanceof ActivityType ? $type->value : $type,
            'status' => $status instanceof ActivityStatus ? $status->value : $status,
            'title' => $title,
            'body' => $body,
            'metadata' => $metadata,
            'due_at' => $dueAt,
            'completed_at' => ($status === ActivityStatus::Completed || $status === ActivityStatus::Completed->value) ? now() : null,
            'creator_id' => $creatorId ?? auth()->id(),
        ]);

        event(new ActivityLogged($activity));

        return $activity;
    }
}
