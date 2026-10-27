<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Events\UserMentioned;
use Odden\Core\Models\Mention;
use Odden\Core\Support\Mentions;
use Odden\Core\Support\UserModel;

class RecordMentionsAction
{
    /**
     * Keep the people mentioned in a note or comment in step with what its author picked, and fire UserMentioned once for each
     * person newly mentioned. Ids that are not teammates (Mentions::teammates) are ignored, so a forged id mentions no one.
     *
     * @param  Model  $mentionable  The note or comment.
     * @param  Model  $subject  The record it is on.
     * @param  array<array-key, mixed>  $userIds  The people picked in the editor.
     * @return list<int> The people newly mentioned.
     */
    public function execute(Model $mentionable, Model $subject, array $userIds, ?int $actorId, ?string $text): array
    {
        $picked = array_values(array_unique(array_map('intval', array_filter($userIds, 'is_numeric'))));
        $allowed = $picked === [] ? [] : array_map('intval', UserModel::query()->whereKey($picked)->pluck(UserModel::make()->getKeyName())->all());

        $mentions = Mention::query()
            ->where('mentionable_type', $mentionable->getMorphClass())
            ->where('mentionable_id', $mentionable->getKey());

        $existing = array_map('intval', (clone $mentions)->pluck('user_id')->all());
        $added = array_values(array_diff($allowed, $existing));
        $removed = array_values(array_diff($existing, $allowed));

        if ($removed !== []) {
            (clone $mentions)->whereIn('user_id', $removed)->delete();
        }

        foreach ($added as $userId) {
            Mention::query()->create([
                'mentionable_type' => $mentionable->getMorphClass(),
                'mentionable_id' => $mentionable->getKey(),
                'user_id' => $userId,
                'mentioned_by' => $actorId,
            ]);
        }

        $excerpt = Mentions::excerpt($text);

        foreach ($added as $userId) {
            event(new UserMentioned($userId, $actorId, $mentionable, $subject, $excerpt));
        }

        return $added;
    }
}
