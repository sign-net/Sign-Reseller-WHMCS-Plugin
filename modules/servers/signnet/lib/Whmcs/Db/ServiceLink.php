<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

/**
 * What the plugin knows about one WHMCS service's Sign.net private label.
 *
 * Kept in its own table rather than in product custom fields: those belong to
 * the product, so moving a service to another product could lose the link, and
 * they cannot carry a unique tenant id or the provisioning attempt that makes a
 * retried CreateAccount safe.
 */
final class ServiceLink
{
    public const STATE_PENDING = 'pending';
    public const STATE_ACTIVE = 'active';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_TERMINATED = 'terminated';
    public const STATE_UNLINKED = 'unlinked';

    /** No provisioning call is in doubt. */
    public const ATTEMPT_NONE = 'none';
    /** A provisioning call was sent and its answer never arrived. */
    public const ATTEMPT_UNKNOWN = 'unknown';
    /** The last provisioning call was refused. */
    public const ATTEMPT_FAILED = 'failed';

    public const PACKAGE_NONE = 'none';
    public const PACKAGE_ASSIGNED = 'assigned';
    /** Waiting for an admin to approve going past the reseller's allowance. */
    public const PACKAGE_HELD = 'held';
    public const PACKAGE_FAILED = 'failed';

    /**
     * @param list<string>                $heldWarnings
     * @param array<string, mixed>|null   $domainSetup
     */
    public function __construct(
        public readonly int $serviceId,
        public readonly int $serverId,
        public readonly ?string $tenantId = null,
        public readonly string $hostname = '',
        public readonly string $portalName = '',
        public readonly string $ownerEmail = '',
        public readonly string $state = self::STATE_PENDING,
        public readonly string $attemptState = self::ATTEMPT_NONE,
        public readonly ?int $attemptAt = null,
        public readonly string $packageState = self::PACKAGE_NONE,
        public readonly array $heldWarnings = [],
        public readonly ?int $approvedAt = null,
        public readonly ?array $domainSetup = null,
        public readonly ?string $lastError = null,
        public readonly int $createdAt = 0,
        public readonly int $updatedAt = 0,
    ) {
    }

    public function isLinked(): bool
    {
        return $this->tenantId !== null;
    }

    /** @param array<string, mixed> $changes property name => new value */
    public function with(array $changes): self
    {
        $values = array_merge(get_object_vars($this), $changes);

        return new self(...$values);
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'service_id' => $this->serviceId,
            'server_id' => $this->serverId,
            'tenant_id' => $this->tenantId,
            'hostname' => $this->hostname,
            'portal_name' => $this->portalName,
            'owner_email' => $this->ownerEmail,
            'state' => $this->state,
            'attempt_state' => $this->attemptState,
            'attempt_at' => $this->attemptAt,
            'package_state' => $this->packageState,
            'held_warnings' => $this->heldWarnings === [] ? null : json_encode($this->heldWarnings),
            'approved_at' => $this->approvedAt,
            'domain_json' => $this->domainSetup === null ? null : json_encode($this->domainSetup),
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            serviceId: (int) $row['service_id'],
            serverId: (int) $row['server_id'],
            tenantId: self::nullableString($row['tenant_id']),
            hostname: (string) $row['hostname'],
            portalName: (string) $row['portal_name'],
            ownerEmail: (string) $row['owner_email'],
            state: (string) $row['state'],
            attemptState: (string) $row['attempt_state'],
            attemptAt: self::nullableInt($row['attempt_at']),
            packageState: (string) $row['package_state'],
            heldWarnings: self::stringList($row['held_warnings']),
            approvedAt: self::nullableInt($row['approved_at']),
            domainSetup: self::jsonObject($row['domain_json']),
            lastError: self::nullableString($row['last_error']),
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    /** @return array<string, mixed>|null */
    private static function jsonObject(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
