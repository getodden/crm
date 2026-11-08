<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Activity;
use Odden\Core\Support\TenantGuard;

class UpdateActivityAction
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<array-key, mixed>|null  $mentionedUserIds
     * @param  array<array-key, string>  $mentionLabels
     */
    public function execute(
        Activity $activity,
        array $attributes,
        Model $subject,
        ?int $actorId = null,
        ?array $mentionedUserIds = null,
        array $mentionLabels = [],
    ): Activity {
        app(TenantGuard::class)->assertOwnedWhenTenanted($activity);

        return DB::transaction(function () use ($activity, $attributes, $subject, $actorId, $mentionedUserIds, $mentionLabels): Activity {
            $activity->update($attributes);

            if ($mentionedUserIds !== null) {
                app(RecordMentionsAction::class)->execute($activity, $subject, $mentionedUserIds, $actorId, $mentionLabels);
            }

            return $activity;
        });
    }
}
