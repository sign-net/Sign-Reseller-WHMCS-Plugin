<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\Model\SuspensionState;
use SignNet\ResellerApi\Model\TenantStatus;

/**
 * The suspension a portal holds, as Sign.net reports it when the portal is read or suspended.
 */
final class PortalSuspension
{
    /**
     * @param int|null $startedAt When it began, in milliseconds since the Unix epoch, as Sign.net gives it.
     * @param bool $isSignNets Sign.net made it, so only Sign.net can lift it.
     * @param string $reason As Sign.net keeps it, trimmed.
     */
    public function __construct(
        public readonly ?int $startedAt,
        public readonly bool $isSignNets,
        public readonly string $reason,
    ) {
    }

    /**
     * The portal's suspension; null when it is not suspended.
     */
    public static function of(PrivateLabel|SuspensionState $portal): ?self
    {
        if (!$portal->isSuspended()) {
            return null;
        }

        return new self(
            $portal->suspendedAt,
            $portal->suspendedBy === TenantStatus::SUSPENDED_BY_PLATFORM,
            SuspensionReason::trimmed((string) $portal->suspendReason),
        );
    }
}
