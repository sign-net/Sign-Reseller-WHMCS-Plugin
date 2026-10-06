<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * One item of the reseller's allowance.
 *
 * $remaining is what is left once everything spending the allowance is taken off: what is promised
 * out to private labels today ($allocatedNow), what the reseller's own team used ($ownUsed), and
 * what labels used beyond their packages ($labelsBeyondAllocation). A label's use inside its
 * package takes nothing more, because allocating it already did. $used is what was actually done,
 * the reseller's own team included, and does not move when a package is assigned.
 */
final class QuotaItem
{
    public function __construct(
        public readonly string $itemCode,
        public readonly int $includedQty,
        public readonly int $allocatedNow,
        public readonly int $used,
        public readonly int $remaining,
        public readonly int $overAllocatedBy,
        public readonly int $ownUsed,
        public readonly int $labelsBeyondAllocation,
    ) {
    }

    /**
     * What spends the allowance without being allocated: the reseller's own use, and labels' use
     * past their packages.
     */
    public function usedOutsideAllocations(): int
    {
        return $this->ownUsed + $this->labelsBeyondAllocation;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException When a required field is missing or mistyped.
     */
    public static function fromArray(array $data): self
    {
        $payload = Payload::of($data);

        return new self(
            $payload->string('itemCode'),
            $payload->int('includedQty'),
            $payload->int('allocatedNow'),
            $payload->int('used'),
            $payload->int('remaining'),
            $payload->int('overAllocatedBy'),
            // An older Sign.net API reports neither, and its remaining did not count them.
            $payload->intOrNull('ownUsed') ?? 0,
            $payload->intOrNull('labelsBeyondAllocation') ?? 0,
        );
    }
}
