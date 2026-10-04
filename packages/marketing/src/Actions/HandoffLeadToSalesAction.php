<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Core\Support\CacheKey;
use Odden\Core\Support\DealSnapshot;
use Odden\Core\Support\UserModel;

class HandoffLeadToSalesAction
{
    /**
     * Instantly hand off a qualified marketing lead to the Sales team.
     * Assigns a sales owner, updates lifecycle stage to SQL, and auto-generates a pipeline Deal and urgent task.
     *
     * @return array{contact: Contact, deal: ?DealSnapshot, owner: ?Model, task_created: bool}
     */
    public function execute(
        Contact $contact,
        ?string $dealName = null,
        ?float $amount = null,
        ?int $pipelineId = null,
        ?int $ownerId = null
    ): array {
        // 1. Resolve or assign the sales owner: an explicit one, the contact's current owner, or the
        // next user in rotation.
        if ($ownerId === null && $contact->owner_id === null) {
            $ownerId = $this->nextOwnerId();
        } elseif ($ownerId === null) {
            $ownerId = $contact->owner_id;
        }

        // 2. Promote Contact to SQL and Active Lead Status
        $updates = [
            'lifecycle_stage' => LifecycleStage::SalesQualifiedLead,
            'lead_status' => LeadStatus::InProgress,
        ];

        if ($ownerId !== null && $contact->owner_id !== $ownerId) {
            $updates['owner_id'] = (int) $ownerId;
        }

        $contact->update($updates);

        /** @var Model|null $owner */
        $owner = $contact->owner;

        // 3. Create Pipeline Deal if a DealGateway is bound (Sales package installed)
        $deal = null;
        if (app()->bound(DealGateway::class)) {
            $deal = app(DealGateway::class)->createOpenDeal(
                contact: $contact,
                name: $dealName ?? "MQL Deal: {$contact->full_name}",
                amount: $amount ?? (float) config('odden-marketing.sales_handoff.default_deal_amount', 10000.00),
                pipelineId: $pipelineId,
                ownerId: $contact->owner_id,
                associateCompany: true,
            );
        }

        // 4. Create Immediate Priority Sales Task
        $ownerName = $owner !== null ? ($owner->name ?? "User #{$owner->getKey()}") : 'Unassigned';
        $contact->logTask(
            title: "🔥 High-Intent MQL Hand-off: {$contact->full_name}",
            dueAt: now()->addHour(),
            body: "Marketing has qualified this contact (Lead Score: {$contact->lead_score}). Immediate outreach required. Assigned to: {$ownerName}."
        );

        return [
            'contact' => $contact->fresh() ?? $contact,
            'deal' => $deal,
            'owner' => $owner,
            'task_created' => true,
        ];
    }

    /**
     * The next user in a round robin over every user, ordered by id. The position is kept in the
     * cache (`odden-marketing:handoff-owner-index`), so it advances once per hand-off.
     */
    protected function nextOwnerId(): ?int
    {
        $ids = UserModel::query()->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            return null;
        }

        $key = CacheKey::for('odden-marketing:handoff-owner-index');
        Cache::add($key, -1, now()->addYears(10));
        $index = (int) Cache::increment($key);

        return (int) $ids[$index % count($ids)];
    }
}
