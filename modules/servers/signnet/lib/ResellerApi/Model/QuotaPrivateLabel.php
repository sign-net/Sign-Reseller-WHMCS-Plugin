<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * One private label's share of the current billing window, as quantities by item code.
 * $billed is, per item, the greater of $allocatedInPeriod and $actual.
 */
final class QuotaPrivateLabel
{
    /**
     * @param array<string, int> $allocatedInPeriod
     * @param array<string, int> $actual
     * @param array<string, int> $billed
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $primaryHost,
        public readonly array $allocatedInPeriod,
        public readonly array $actual,
        public readonly array $billed,
    ) {
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
            $payload->string('tenantId'),
            $payload->string('primaryHost'),
            $payload->intMap('allocatedInPeriod'),
            $payload->intMap('actual'),
            $payload->intMap('billed'),
        );
    }
}
