<?php

declare(strict_types=1);

namespace Odden\Core\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone was picked with "@" in a note or a comment, for the first time in it: editing it later fires this only for people added
 * then. A host application listens to tell the person (who may be the author: that is the listener's choice).
 */
class UserMentioned
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Model  $mentionable  The note (an activity) or comment the person is mentioned in.
     * @param  Model  $subject  The record it is on: a contact, company or deal for a note, a ticket for a comment.
     * @param  string  $excerpt  The start of the text, plain, for a notification.
     */
    public function __construct(
        public int $mentionedUserId,
        public ?int $actorId,
        public Model $mentionable,
        public Model $subject,
        public string $excerpt,
    ) {}
}
