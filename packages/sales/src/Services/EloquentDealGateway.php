<?php

declare(strict_types=1);

namespace Odden\Sales\Services;

use Illuminate\Support\Collection;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Enums\DealOutcome;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\DealSnapshot;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

class EloquentDealGateway implements DealGateway
{
    public function createOpenDeal(
        Contact $contact,
        string $name,
        float $amount,
        ?int $pipelineId = null,
        ?int $stageId = null,
        ?int $ownerId = null,
        bool $associateCompany = false,
    ): ?DealSnapshot {
        $pipeline = $pipelineId !== null
            ? Pipeline::query()->find($pipelineId)
            : Pipeline::query()->first();

        if ($pipeline === null) {
            return null;
        }

        $stageId ??= $pipeline->stages()->orderBy('sort_order', 'asc')->first()?->id;

        if ($stageId === null) {
            return null;
        }

        $deal = Deal::create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stageId,
            'name' => $name,
            'amount' => $amount,
            'status' => DealStatus::Open,
            'owner_id' => $ownerId ?? $contact->owner_id,
        ]);

        $contact->associateWith($deal, 'primary');

        if ($associateCompany) {
            /** @var Company|null $company */
            $company = $contact->companies()->first();
            $company?->associateWith($deal, 'primary');
        }

        return $this->snapshot($deal);
    }

    public function findMany(array $ids): Collection
    {
        return Deal::query()->whereIn('id', $ids)->get()->map(fn (Deal $deal): DealSnapshot => $this->snapshot($deal));
    }

    private function snapshot(Deal $deal): DealSnapshot
    {
        return new DealSnapshot(
            id: (int) $deal->id,
            name: (string) $deal->name,
            status: DealOutcome::from($deal->status->value),
            amount: (float) $deal->amount,
            pipelineId: (int) $deal->pipeline_id,
            stageId: (int) $deal->stage_id,
            createdAt: $deal->created_at,
            closedAt: $deal->closed_at,
        );
    }
}
