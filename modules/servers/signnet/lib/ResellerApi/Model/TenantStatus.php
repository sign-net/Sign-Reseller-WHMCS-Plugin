<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

/**
 * Values of a private label's status and of who suspended it.
 */
final class TenantStatus
{
    public const ACTIVE = 'Active';
    public const SUSPENDED = 'Suspended';

    public const SUSPENDED_BY_PLATFORM = 'Platform';
    public const SUSPENDED_BY_RESELLER = 'Reseller';

    private function __construct()
    {
    }
}
