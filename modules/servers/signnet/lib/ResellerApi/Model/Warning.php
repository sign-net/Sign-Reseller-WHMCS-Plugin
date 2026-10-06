<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * How far an allocation would take one item past the reseller's allowance.
 */
final class Warning
{
    public function __construct(
        public readonly string $itemCode,
        public readonly int $requested,
        public readonly int $remaining,
        public readonly int $excess,
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
            $payload->string('itemCode'),
            $payload->int('requested'),
            $payload->int('remaining'),
            $payload->int('excess'),
        );
    }
}
