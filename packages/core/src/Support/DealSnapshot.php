<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Carbon\CarbonInterface;
use Odden\Core\Enums\DealOutcome;

/**
 * Read-only description of a deal, as returned by a DealGateway.
 */
final readonly class DealSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public DealOutcome $status,
        public float $amount,
        public int $pipelineId,
        public int $stageId,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $closedAt,
    ) {}
}
