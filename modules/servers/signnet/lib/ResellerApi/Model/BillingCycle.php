<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

/**
 * Billing cycles the backend accepts for packages and add-ons. Biannual means every six months.
 */
final class BillingCycle
{
    public const MONTHLY = 'Monthly';
    public const QUARTERLY = 'Quarterly';
    public const BIANNUAL = 'Biannual';
    public const ANNUAL = 'Annual';
    public const ONE_TIME = 'OneTime';

    private function __construct()
    {
    }
}
