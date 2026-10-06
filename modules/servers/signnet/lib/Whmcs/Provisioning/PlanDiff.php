<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Model\AssignedAddon;
use SignNet\ResellerApi\Model\Assignment;

/**
 * The calls that take a portal from the package and add-ons it holds to the ones it should.
 *
 * Only add-ons WHMCS manages (those the product sells) change without a package swap; one attached
 * in Sign.net directly is left alone. A swap cannot keep any add-on, because Sign.net ends a
 * portal's add-ons with its package.
 */
final class PlanDiff
{
    /**
     * @param list<AssignedAddon> $detach
     * @param array<string, int> $attach Add-on id => quantity.
     * @param list<string> $warnings What the change costs or does out of sight, for the activity log.
     */
    private function __construct(
        public readonly bool $unassign,
        public readonly bool $assign,
        public readonly array $detach,
        public readonly array $attach,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param array<string, int> $managedAddons Add-on id => quantity wanted, for every add-on WHMCS
     *     manages (0 = none).
     */
    public static function between(?Assignment $current, string $packageId, array $managedAddons): self
    {
        $wanted = array_filter($managedAddons, static fn (int $quantity): bool => $quantity > 0);
        if ($current === null) {
            return new self(false, true, [], $wanted, []);
        }
        if ($current->packageId !== $packageId) {
            return new self(true, true, [], $wanted, self::swapWarnings($current, $managedAddons));
        }

        $detach = [];
        $attach = [];
        $warnings = [];
        foreach ($managedAddons as $addonId => $quantity) {
            $attached = array_values(array_filter(
                $current->addons,
                static fn (AssignedAddon $addon): bool => $addon->addonId === $addonId,
            ));
            $held = array_sum(array_map(static fn (AssignedAddon $addon): int => $addon->quantity, $attached));
            if ($held === $quantity) {
                continue;
            }
            array_push($detach, ...$attached);
            if ($quantity > 0) {
                $attach[$addonId] = $quantity;
            }
            if ($held > 0 && $quantity > 0) {
                $warnings[] = sprintf(
                    'Add-on %s goes from %d to %d by replacing its attachment.',
                    $attached[0]->code,
                    $held,
                    $quantity,
                );
            }
        }

        return new self(false, false, $detach, $attach, $warnings);
    }

    public function isEmpty(): bool
    {
        return !$this->unassign && !$this->assign && $this->detach === [] && $this->attach === [];
    }

    /**
     * @param array<string, int> $managedAddons
     *
     * @return list<string>
     */
    private static function swapWarnings(Assignment $current, array $managedAddons): array
    {
        $warnings = [sprintf(
            'Package %s is swapped: the portal holds no package for a moment, and its carried credit is written off.',
            $current->code,
        )];
        foreach ($current->addons as $addon) {
            if (!array_key_exists($addon->addonId, $managedAddons)) {
                $warnings[] = sprintf(
                    'Add-on %s (x%d) was attached in Sign.net, not WHMCS, and ends with the old package.',
                    $addon->code,
                    $addon->quantity,
                );
            }
        }

        return $warnings;
    }
}
