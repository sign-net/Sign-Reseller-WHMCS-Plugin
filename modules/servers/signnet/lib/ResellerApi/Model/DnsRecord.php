<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * A DNS record the customer must create for their portal hostname.
 */
final class DnsRecord
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $value,
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

        return new self($payload->string('type'), $payload->string('name'), $payload->string('value'));
    }
}
