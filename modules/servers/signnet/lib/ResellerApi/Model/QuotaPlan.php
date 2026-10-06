<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * The Sign.net plan the reseller itself is on.
 */
final class QuotaPlan
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $billingCycle,
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

        return new self($payload->string('packageName'), $payload->string('billingCycle'));
    }
}
