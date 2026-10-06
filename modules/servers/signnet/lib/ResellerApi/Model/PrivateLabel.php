<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * One private label, with its owner, package and domain setup. Timestamps are milliseconds since
 * the Unix epoch.
 */
final class PrivateLabel
{
    /**
     * @param string $status A TenantStatus value.
     * @param string|null $suspendedBy TenantStatus::SUSPENDED_BY_PLATFORM or SUSPENDED_BY_RESELLER.
     * @param PortalOwner|null $owner Null when the tenant has no owner account.
     * @param Assignment|null $package Null when no package is assigned.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $primaryHost,
        public readonly ?string $customDomain,
        public readonly string $status,
        public readonly int $createdAt,
        public readonly ?int $suspendedAt,
        public readonly ?string $suspendedBy,
        public readonly ?string $suspendReason,
        public readonly ?PortalOwner $owner,
        public readonly int $memberCount,
        public readonly ?Assignment $package,
        public readonly DomainSetup $domainSetup,
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
            $payload->string('primaryHost'),
            $payload->stringOrNull('customDomain'),
            $payload->string('status'),
            $payload->int('createdAt'),
            $payload->intOrNull('suspendedAt'),
            $payload->stringOrNull('suspendedBy'),
            $payload->stringOrNull('suspendReason'),
            $payload->decodeOrNull('owner', PortalOwner::fromArray(...)),
            $payload->int('memberCount'),
            $payload->decodeOrNull('assignment', Assignment::fromArray(...)),
            $payload->decode('domainSetup', DomainSetup::fromArray(...)),
        );
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::SUSPENDED;
    }
}
