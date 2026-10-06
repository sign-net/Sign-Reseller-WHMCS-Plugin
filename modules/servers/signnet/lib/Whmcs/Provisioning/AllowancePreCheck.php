<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Model\Addon;
use SignNet\ResellerApi\Model\Package;
use SignNet\ResellerApi\Model\Quota;
use SignNet\ResellerApi\Model\Warning;

/**
 * Whether an order fits what is left of the reseller's allowance, checked before anything is
 * created, so an order that would go past it waits for approval with nothing half-made. Sign.net
 * still has the last word: it asks for confirmation itself when an allocation does not fit.
 */
final class AllowancePreCheck
{
    /**
     * @param list<array{Addon, int}> $addons Each add-on ordered, with its quantity.
     *
     * @return array<string, int> Quantities by item code.
     */
    public static function requested(Package $package, array $addons): array
    {
        $requested = [];
        foreach ($package->items as $item) {
            $requested[$item->itemCode] = ($requested[$item->itemCode] ?? 0) + $item->includedQty;
        }
        foreach ($addons as [$addon, $quantity]) {
            foreach ($addon->items as $item) {
                $requested[$item->itemCode] = ($requested[$item->itemCode] ?? 0) + $item->grantedQty * $quantity;
            }
        }

        return $requested;
    }

    /**
     * @param array<string, int> $requested Quantities by item code.
     *
     * @return list<Warning> One per item the order would take past what is left.
     */
    public static function shortfalls(Quota $quota, array $requested): array
    {
        $shortfalls = [];
        foreach ($requested as $itemCode => $quantity) {
            $remaining = max(0, $quota->item($itemCode)->remaining ?? 0);
            if ($quantity > $remaining) {
                $shortfalls[] = new Warning($itemCode, $quantity, $remaining, $quantity - $remaining);
            }
        }

        return $shortfalls;
    }

    /**
     * "documents: 500 needed, 120 left (380 over)".
     */
    public static function describe(Warning $shortfall): string
    {
        return sprintf(
            '%s: %d needed, %d left (%d over)',
            $shortfall->itemCode,
            $shortfall->requested,
            $shortfall->remaining,
            $shortfall->excess,
        );
    }
}
