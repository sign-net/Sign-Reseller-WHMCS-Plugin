<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * A row of the reseller's private labels.
 */
final class PrivateLabelSummary
{
    /**
     * @param string $status A TenantStatus value.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $primaryHost,
        public readonly string $status,
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
            $payload->string('status'),
        );
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::SUSPENDED;
    }
}
