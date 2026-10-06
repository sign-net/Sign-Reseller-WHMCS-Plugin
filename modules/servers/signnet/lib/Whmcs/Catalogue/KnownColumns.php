<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use WHMCS\Database\Capsule;

/**
 * Inserts into WHMCS tables whose columns differ between WHMCS versions.
 */
final class KnownColumns
{
    /**
     * Inserts a row and answers its id. $values are always written; each of $fillers only when this
     * WHMCS has that column, because WHMCS declares many text columns NOT NULL without a default and
     * the set of columns (timestamps especially) has changed between versions.
     *
     * @param array<string, scalar> $values
     * @param array<string, scalar> $fillers
     */
    public static function insertGetId(string $table, array $values, array $fillers): int
    {
        $columns = array_flip(Capsule::schema()->getColumnListing($table));

        return Capsule::table($table)->insertGetId($values + array_intersect_key($fillers, $columns));
    }
}
