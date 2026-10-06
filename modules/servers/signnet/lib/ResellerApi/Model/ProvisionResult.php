<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * The answer to provisioning a private label. The tenant exists whatever $package says: assigning the
 * package happens after the tenant is created and is not part of the same transaction.
 */
final class ProvisionResult
{
    /**
     * @param AllocationOutcome|null $package Null when the request asked for no package.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $ownerUserId,
        public readonly DomainSetup $domainSetup,
        public readonly ?AllocationOutcome $package = null,
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
            $payload->string('ownerUserId'),
            $payload->decode('domainSetup', DomainSetup::fromArray(...)),
            $payload->decodeOrNull('package', AllocationOutcome::fromArray(...)),
        );
    }
}
