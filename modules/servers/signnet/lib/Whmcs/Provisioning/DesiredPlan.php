<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

/**
 * The package and add-ons a service says its portal should hold.
 */
final class DesiredPlan
{
    /**
     * @param array<array-key, int> $addonQuantities Add-on code => quantity, for every add-on the
     *     product sells (0 = none). Add-ons the product does not sell are left alone.
     */
    public function __construct(
        public readonly string $packageId,
        public readonly array $addonQuantities = [],
    ) {
    }
}
