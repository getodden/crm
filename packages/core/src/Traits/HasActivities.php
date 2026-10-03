<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Association;

trait HasActivities
{
    /**
     * Timeline activities associated with this record.
     *
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('created_at');
    }

    /**
     * Unified chronological timeline of activities.
     * Rolls up activities from all associated records when enabled.
     *
     * @return Builder<Activity>
     */
    public function timeline(bool $includeAssociated = true): Builder
    {
        $query = Activity::query()
            ->where(function (Builder $q): void {
                $q->where('subject_type', $this->getMorphClass())
                    ->where('subject_id', $this->getKey());
            });

        if ($includeAssociated) {
            $associatedChildren = Association::query()
                ->where('parent_type', $this->getMorphClass())
                ->where('parent_id', $this->getKey())
                ->get(['child_type', 'child_id']);

            $associatedParents = Association::query()
                ->where('child_type', $this->getMorphClass())
                ->where('child_id', $this->getKey())
                ->get(['parent_type', 'parent_id']);

            /** @var array<string, list<int>> $targetsByType */
            $targetsByType = [];

            foreach ($associatedChildren as $assoc) {
                $targetsByType[$assoc->child_type][] = (int) $assoc->child_id;
            }

            foreach ($associatedParents as $assoc) {
                $targetsByType[$assoc->parent_type][] = (int) $assoc->parent_id;
            }

            if (! empty($targetsByType)) {
                $query->orWhere(function (Builder $sub) use ($targetsByType): void {
                    foreach ($targetsByType as $type => $ids) {
                        $sub->orWhere(function (Builder $targetSub) use ($type, $ids): void {
                            $targetSub->where('subject_type', $type)
                                ->whereIn('subject_id', array_unique($ids));
                        });
                    }
                });
            }
        }

        return $query->latest('created_at');
    }

    /**
     * Log a generic activity on this record.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logActivity(
        ActivityType|string $type,
        string $title,
        ?string $body = null,
        array $metadata = [],
        ActivityStatus|string $status = ActivityStatus::Completed,
        ?CarbonInterface $dueAt = null,
        ?int $creatorId = null
    ): Activity {
        return app(LogActivityAction::class)->execute(
            subject: $this,
            type: $type,
            title: $title,
            body: $body,
            metadata: $metadata,
            status: $status,
            dueAt: $dueAt,
            creatorId: $creatorId,
        );
    }

    /**
     * Log a quick note on this record.
     */
    public function logNote(string $body, ?string $title = null, ?int $creatorId = null): Activity
    {
        return $this->logActivity(
            type: ActivityType::Note,
            title: $title ?? 'Note added',
            body: $body,
            creatorId: $creatorId
        );
    }

    /**
     * Log a phone call on this record.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logCall(string $title, ?string $body = null, array $metadata = [], ?int $creatorId = null): Activity
    {
        return $this->logActivity(
            type: ActivityType::Call,
            title: $title,
            body: $body,
            metadata: $metadata,
            creatorId: $creatorId
        );
    }

    /**
     * Log a task on this record.
     */
    public function logTask(string $title, ?CarbonInterface $dueAt = null, ?string $body = null, ?int $creatorId = null): Activity
    {
        return $this->logActivity(
            type: ActivityType::Task,
            title: $title,
            body: $body,
            status: ActivityStatus::Pending,
            dueAt: $dueAt,
            creatorId: $creatorId
        );
    }
}
