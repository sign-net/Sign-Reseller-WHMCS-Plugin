<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * A row of the add-on catalogue. Rows carry no $description; getAddon() has it.
 * Prices are in minor currency units; timestamps are milliseconds since the Unix epoch.
 */
final class AddonSummary
{
    /**
     * @param string $billingCycle A BillingCycle value.
     */
    public function __construct(
        public readonly string $addonId,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $currency,
        public readonly int $priceMinor,
        public readonly string $billingCycle,
        public readonly bool $isActive,
        public readonly int $createdAt,
        public readonly int $updatedAt,
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
            $payload->string('code'),
            $payload->string('name'),
            $payload->stringOrNull('description'),
            $payload->string('currency'),
            $payload->int('priceMinor'),
            $payload->string('billingCycle'),
            $payload->bool('isActive'),
            $payload->int('createdAt'),
            $payload->int('updatedAt'),
        );
    }
}
