<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * A user of a private label's portal.
 */
final class PortalUser
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    /**
     * @param string $role ROLE_OWNER, ROLE_ADMIN or ROLE_MEMBER.
     * @param int $createdAt Milliseconds since the Unix epoch.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $role,
        public readonly string $status,
        public readonly int $createdAt,
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
            $payload->string('email'),
            $payload->string('firstName'),
            $payload->string('lastName'),
            $payload->string('role'),
            $payload->string('status'),
            $payload->int('createdAt'),
        );
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }
}
