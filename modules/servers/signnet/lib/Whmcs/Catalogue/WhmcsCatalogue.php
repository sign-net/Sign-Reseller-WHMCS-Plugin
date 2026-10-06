<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use Illuminate\Database\Query\Builder;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Version;
use WHMCS\Database\Capsule;

/**
 * WHMCS's own products, product groups and server groups, each as id => name.
 */
final class WhmcsCatalogue
{
    /**
     * @return array<int, string>
     */
    public static function products(): array
    {
        return self::names(Capsule::table('tblproducts'));
    }

    /**
     * The products this module provisions.
     *
     * @return array<int, string>
     */
    public static function signNetProducts(): array
    {
        return self::names(Capsule::table('tblproducts')->where('servertype', Version::MODULE));
    }

    /**
     * @return array<int, string>
     */
    public static function productGroups(): array
    {
        return self::names(Capsule::table('tblproductgroups'));
    }

    /**
     * @return array<int, string>
     */
    public static function serverGroups(): array
    {
        return self::names(Capsule::table('tblservergroups'));
    }

    /**
     * @return array<int, string>
     */
    private static function names(Builder $query): array
    {
        $names = [];
        foreach (Rows::all($query->select('id', 'name')->orderBy('name')->orderBy('id')) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }
}
