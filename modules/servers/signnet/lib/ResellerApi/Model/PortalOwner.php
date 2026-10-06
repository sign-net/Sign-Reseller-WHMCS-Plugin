<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

final class PortalOwner
{
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
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
            $payload->string('userId'),
            $payload->string('email'),
            $payload->string('firstName'),
            $payload->string('lastName'),
        );
    }
}
