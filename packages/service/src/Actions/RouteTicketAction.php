<?php

declare(strict_types=1);

namespace Odden\Service\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\UserModel;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;
use Odden\Service\Models\TicketRoutingRule;

class RouteTicketAction
{
    /**
     * Route a Ticket to a support agent based on active rules.
     *
     * @return array{assigned_user_id: int, rule: TicketRoutingRule}|null
     */
    public function execute(Ticket $ticket): ?array
    {
        /** @var Collection<int, TicketRoutingRule> $rules */
        $rules = TicketRoutingRule::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($rules as $rule) {
            $pool = $rule->assigned_user_ids;
            if (empty($pool)) {
                continue;
            }

            if ($rule->criteria !== null && ! empty($rule->criteria)) {
                if (! $this->matchesCriteria($ticket, $rule->criteria)) {
                    continue;
                }
            }

            $selectedUserId = $this->selectUser($rule, $pool);

            if ($selectedUserId !== null) {
                $updates = ['owner_id' => $selectedUserId];
                if ($ticket->status === TicketStatus::New) {
                    $updates['status'] = TicketStatus::Open;
                }

                $ticket->update($updates);

                /** @var Model|null $user */
                $user = UserModel::query()->find($selectedUserId);
                $userName = UserModel::displayName($user, "Agent #{$selectedUserId}");

                if ($ticket->contact !== null) {
                    $ticket->contact->logNote(
                        body: "Support Ticket #{$ticket->ticket_number} auto-assigned to {$userName} via routing rule [{$rule->name}]."
                    );
                }

                return [
                    'assigned_user_id' => $selectedUserId,
                    'rule' => $rule,
                ];
            }
        }

        return null;
    }

    /**
     * Select the next user from the pool via round-robin.
     *
     * @param  list<int>  $pool
     */
    protected function selectUser(TicketRoutingRule $rule, array $pool): ?int
    {
        $count = count($pool);
        if ($count === 0) {
            return null;
        }

        // Read and advance the pointer under a row lock: two tickets routed at the same moment must not both
        // read the same index and go to the same person.
        return DB::transaction(function () use ($rule, $pool, $count): int {
            $locked = TicketRoutingRule::query()->whereKey($rule->getKey())->lockForUpdate()->first() ?? $rule;

            $nextIndex = ($locked->last_assigned_index + 1) % $count;
            $selectedUserId = $pool[$nextIndex] ?? $pool[0];

            $locked->update(['last_assigned_index' => $nextIndex]);
            $rule->last_assigned_index = $nextIndex;

            return (int) $selectedUserId;
        });
    }

    /**
     * Determine if ticket matches rule criteria.
     *
     * @param  array<string, mixed>  $criteria
     */
    protected function matchesCriteria(Ticket $ticket, array $criteria): bool
    {
        foreach ($criteria as $key => $expected) {
            if ($key === 'priority') {
                if ($ticket->priority->value !== (string) $expected) {
                    return false;
                }
            } elseif ($key === 'source') {
                if ($ticket->source->value !== (string) $expected) {
                    return false;
                }
            } elseif ($key === 'keyword') {
                $haystack = mb_strtolower($ticket->subject.' '.($ticket->description ?? ''));
                if (! str_contains($haystack, mb_strtolower((string) $expected))) {
                    return false;
                }
            } elseif ($key === 'has_company') {
                $hasCompany = $ticket->company_id !== null;
                if ($hasCompany !== (bool) $expected) {
                    return false;
                }
            }
        }

        return true;
    }
}
