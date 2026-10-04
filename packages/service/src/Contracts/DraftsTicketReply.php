<?php

declare(strict_types=1);

namespace Odden\Service\Contracts;

use Illuminate\Database\Eloquent\Model;
use Odden\Service\Models\Ticket;

/**
 * Drafts a reply to a ticket for an agent to review and edit. Nothing is ever sent from here: the draft only fills the
 * reply box, and the agent posts it (or not) like any other reply.
 *
 * The built-in implementation (DraftTicketReplyAction) is rule-based: it picks the canned response that best matches the
 * ticket, adds help articles that match, and calls no outside service. An application or add-on can rebind this contract
 * to another implementation (one backed by a language model, say) as long as it returns the same shape. The panel asks
 * the container for this contract, so nothing else has to change.
 *
 *     $this->app->bind(DraftsTicketReply::class, MyReplyDrafter::class);
 */
interface DraftsTicketReply
{
    /**
     * @param  Model|null  $agent  The agent who will review the draft; used for the sign-off and for which canned responses they may use.
     * @return array{
     *     body: string,
     *     sources: list<string>,
     *     rationale: string
     * }
     */
    public function execute(Ticket $ticket, ?Model $agent = null): array;
}
