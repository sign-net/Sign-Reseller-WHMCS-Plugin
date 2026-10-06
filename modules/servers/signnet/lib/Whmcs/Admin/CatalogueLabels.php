<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\ResellerApi\Model\BillingCycle;
use SignNet\ResellerApi\Model\ItemCode;

/**
 * How the plugin's pages, the addon's and the client area's, name Sign.net's billing cycles and items.
 */
final class CatalogueLabels
{
    public const CYCLES = [
        BillingCycle::MONTHLY => 'Monthly',
        BillingCycle::QUARTERLY => 'Quarterly',
        BillingCycle::BIANNUAL => 'Every 6 months',
        BillingCycle::ANNUAL => 'Annually',
        BillingCycle::ONE_TIME => 'One time',
    ];

    private const ITEMS = [
        ItemCode::DOCUMENTS => 'Documents',
        ItemCode::SEATS => 'Seats',
        ItemCode::TEMPLATES => 'Templates',
        ItemCode::NOTARIZATIONS => 'Notarisations',
    ];

    public static function cycle(string $billingCycle): string
    {
        return self::CYCLES[$billingCycle] ?? $billingCycle;
    }

    public static function item(string $itemCode): string
    {
        return self::ITEMS[$itemCode] ?? $itemCode;
    }
}
