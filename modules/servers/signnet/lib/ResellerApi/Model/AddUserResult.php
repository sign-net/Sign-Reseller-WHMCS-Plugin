<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

final class AddUserResult
{
    /**
     * @param bool $created False when a user with that email already existed and was reused.
     */
    public function __construct(
        public readonly string $userId,
        public readonly bool $created,
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

        return new self($payload->string('userID'), $payload->bool('created'));
    }
}
