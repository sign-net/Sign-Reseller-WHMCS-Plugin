<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * One private label's usage in a month.
 */
final class UsagePrivateLabel
{
    /**
     * @param array<string, int> $metrics Counts by metric: documents, points, seats, templates.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $primaryHost,
        public readonly array $metrics,
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
            $payload->intMap('metrics'),
        );
    }
}
