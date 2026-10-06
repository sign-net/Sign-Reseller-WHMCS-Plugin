<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * A private label's suspension after a suspend or unsuspend call.
 */
final class SuspensionState
{
    /**
     * @param string $status A TenantStatus value.
     * @param int|null $suspendedAt Milliseconds since the Unix epoch.
     * @param string|null $suspendedBy TenantStatus::SUSPENDED_BY_PLATFORM or SUSPENDED_BY_RESELLER.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $status,
        public readonly ?int $suspendedAt,
        public readonly ?string $suspendedBy,
        public readonly ?string $suspendReason,
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
            $payload->string('status'),
            $payload->intOrNull('suspendedAt'),
            $payload->stringOrNull('suspendedBy'),
            $payload->stringOrNull('suspendReason'),
        );
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::SUSPENDED;
    }

    public function isSuspendedByPlatform(): bool
    {
        return $this->suspendedBy === TenantStatus::SUSPENDED_BY_PLATFORM;
    }
}
