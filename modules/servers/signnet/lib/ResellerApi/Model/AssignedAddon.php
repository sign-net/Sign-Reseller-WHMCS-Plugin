<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * An add-on attached to a private label's package.
 */
final class AssignedAddon
{
    public function __construct(
        public readonly string $subscriptionAddonId,
        public readonly string $addonId,
        public readonly string $code,
        public readonly string $name,
        public readonly int $quantity,
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
            $payload->string('subscriptionAddonId'),
            $payload->string('addonId'),
            $payload->string('code'),
            $payload->string('name'),
            $payload->int('quantity'),
        );
    }
}
