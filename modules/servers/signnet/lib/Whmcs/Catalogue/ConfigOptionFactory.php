<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use SignNet\ResellerApi\Model\Addon;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Provisioning\OrderDetails;
use WHMCS\Database\Capsule;

/**
 * The configurable option through which WHMCS products sell a Sign.net add-on: a group of its own
 * holding one Quantity option named "addon_<CODE>|<Name>", from which the module reads how many
 * units a customer ordered.
 */
final class ConfigOptionFactory
{
    /** WHMCS's option type for a quantity the customer enters. */
    private const QUANTITY_TYPE = 4;

    public function find(string $addonCode): ?AddonOption
    {
        $options = Capsule::table('tblproductconfigoptions as configoption')
            ->join('tblproductconfiggroups as configgroup', 'configgroup.id', '=', 'configoption.gid')
            ->select('configoption.id', 'configoption.gid', 'configoption.optionname', 'configoption.qtymaximum')
            ->addSelect('configgroup.name')
            ->where('configoption.optionname', 'like', OrderDetails::ADDON_OPTION_PREFIX . '%')
            ->orderBy('configoption.id');
        foreach (Rows::all($options) as $row) {
            if (self::addonCodeOf((string) $row['optionname']) === strtoupper($addonCode)) {
                return new AddonOption(
                    (int) $row['id'],
                    (int) $row['gid'],
                    (string) $row['name'],
                    (int) $row['qtymaximum'],
                    self::linkedProductIds((int) $row['gid']),
                );
            }
        }

        return null;
    }

    /**
     * Creates the add-on's option priced per unit from the add-on or, when it already exists,
     * changes only its maximum, leaving its WHMCS prices as they are. Either way the option is
     * then linked to exactly the Sign.net products in $productIds; links to other products stay.
     *
     * @param list<int> $productIds
     *
     * @return AddonOption The option as it now stands.
     *
     * @throws CatalogueException When WHMCS lacks the add-on's currency.
     */
    public function save(Addon $addon, int $maxQuantity, array $productIds): AddonOption
    {
        Capsule::connection()->transaction(function () use ($addon, $maxQuantity, $productIds): void {
            $existing = $this->find($addon->code);
            if ($existing === null) {
                $groupId = self::create($addon, $maxQuantity);
            } else {
                $groupId = $existing->groupId;
                Capsule::table('tblproductconfigoptions')
                    ->where('id', $existing->optionId)
                    ->update(['qtymaximum' => $maxQuantity]);
            }
            self::linkSignNetProducts($groupId, $productIds);
        });

        return $this->find($addon->code) ?? throw new \LogicException('The add-on option was not saved.');
    }

    /**
     * @return int The new option group's id.
     */
    private static function create(Addon $addon, int $maxQuantity): int
    {
        $currencyId = Currencies::idForCode($addon->currency);
        $groupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'name' => 'Sign.net add-on: ' . $addon->name,
            'description' => sprintf('Sells Sign.net add-on %s by the unit.', strtoupper($addon->code)),
        ]);
        $optionId = Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $groupId,
            'optionname' => OrderDetails::ADDON_OPTION_PREFIX . strtoupper($addon->code) . '|' . $addon->name,
            'optiontype' => self::QUANTITY_TYPE,
            'qtyminimum' => 0,
            'qtymaximum' => $maxQuantity,
            'order' => 0,
            'hidden' => 0,
        ]);
        $subOptionId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
            'configid' => $optionId,
            'optionname' => $addon->name,
            'sortorder' => 0,
            'hidden' => 0,
        ]);
        Capsule::table('tblpricing')->insert(
            ['type' => 'configoptions', 'currency' => $currencyId, 'relid' => $subOptionId]
            + PriceMapper::configOptionPricing($addon->billingCycle, $addon->priceMinor),
        );

        return $groupId;
    }

    /**
     * @param list<int> $productIds
     */
    private static function linkSignNetProducts(int $groupId, array $productIds): void
    {
        $signNetProducts = array_keys(WhmcsCatalogue::signNetProducts());
        $wanted = array_intersect($signNetProducts, $productIds);
        Capsule::table('tblproductconfiglinks')
            ->where('gid', $groupId)
            ->whereIn('pid', array_values(array_diff($signNetProducts, $wanted)))
            ->delete();
        foreach (array_diff($wanted, self::linkedProductIds($groupId)) as $productId) {
            Capsule::table('tblproductconfiglinks')->insert(['gid' => $groupId, 'pid' => $productId]);
        }
    }

    /**
     * @return list<int>
     */
    private static function linkedProductIds(int $groupId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['pid'],
            Rows::all(Capsule::table('tblproductconfiglinks')->select('pid')->where('gid', $groupId)->orderBy('pid')),
        );
    }

    /**
     * The add-on code an option name carries, read the way the module reads it; null for other options.
     */
    private static function addonCodeOf(string $optionName): ?string
    {
        $key = explode('|', $optionName, 2)[0];
        if (stripos($key, OrderDetails::ADDON_OPTION_PREFIX) !== 0) {
            return null;
        }

        return strtoupper(trim(substr($key, strlen(OrderDetails::ADDON_OPTION_PREFIX))));
    }
}
