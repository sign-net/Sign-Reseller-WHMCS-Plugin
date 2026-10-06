<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * The package a private label holds, its add-ons, and what it currently takes from the
 * reseller's allowance.
 */
final class Assignment
{
    /**
     * @param int $assignedAt Milliseconds since the Unix epoch.
     * @param list<AssignedAddon> $addons
     * @param array<string, int> $allocated Quantities by item code.
     */
    public function __construct(
        public readonly string $assignmentId,
        public readonly string $packageId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $currency,
        public readonly int $assignedAt,
        public readonly array $addons,
        public readonly array $allocated,
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
            $payload->string('id'),
            $payload->string('packageId'),
            $payload->string('packageCode'),
            $payload->string('packageName'),
            $payload->string('currency'),
            $payload->int('assignedAt'),
            $payload->decodeList('addons', AssignedAddon::fromArray(...)),
            $payload->intMap('allocated'),
        );
    }
}
