<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use SignNet\Whmcs\Db\Rows;
use WHMCS\Database\Capsule;

/**
 * The currencies WHMCS is set up to invoice in (tblcurrencies).
 */
final class Currencies
{
    /**
     * @return list<string> Currency codes, WHMCS's default currency first.
     */
    public static function codes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['code'],
            Rows::all(Capsule::table('tblcurrencies')->select('code')->orderByDesc('default')->orderBy('id')),
        );
    }

    /**
     * @throws CatalogueException When WHMCS has no currency with that code.
     */
    public static function idForCode(string $code): int
    {
        $row = Rows::first(Capsule::table('tblcurrencies')->select('id')->where('code', strtoupper(trim($code))));
        if ($row === null) {
            throw new CatalogueException(sprintf(
                'WHMCS has no %s currency. Add it under System Settings > Currencies, then try again.',
                $code,
            ));
        }

        return (int) $row['id'];
    }
}
