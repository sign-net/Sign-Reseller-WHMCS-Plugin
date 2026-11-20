<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Provisioning\OrderDetails;
use WHMCS\Database\Capsule;

/**
 * The text of the add-on options in WHMCS's tables. Earlier versions of the plugin stored an add-on's
 * name there plain, where WHMCS keeps text HTML-encoded and prints it as stored.
 */
final class AddonOptionNames
{
    /**
     * HTML-encodes the name and description of each add-on option's group, the name after the
     * option's "|", and the names of its price rows. Entities already there are kept, so text WHMCS
     * or this version encoded stays as it is, and running this again changes nothing. The
     * "addon_<CODE>" part, which the module reads, is left alone.
     */
    public static function encodeStored(): void
    {
        $options = self::addonOptions();
        foreach ($options as $option) {
            self::write('tblproductconfigoptions', $option, [
                'optionname' => self::encodeDisplayName((string) $option['optionname']),
            ]);
        }
        $subOptions = Capsule::table('tblproductconfigoptionssub')
            ->select('id', 'optionname')
            ->whereIn('configid', array_column($options, 'id'));
        foreach (Rows::all($subOptions) as $row) {
            self::write('tblproductconfigoptionssub', $row, [
                'optionname' => self::encode((string) $row['optionname']),
            ]);
        }
        $groups = Capsule::table('tblproductconfiggroups')
            ->select('id', 'name', 'description')
            ->whereIn('id', array_unique(array_column($options, 'gid')));
        foreach (Rows::all($groups) as $row) {
            self::write('tblproductconfiggroups', $row, [
                'name' => self::encode((string) $row['name']),
                'description' => self::encode((string) $row['description']),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>> The options whose name starts "addon_", as the module reads them.
     */
    private static function addonOptions(): array
    {
        $prefix = OrderDetails::ADDON_OPTION_PREFIX;
        $rows = Rows::all(Capsule::table('tblproductconfigoptions')
            ->select('id', 'gid', 'optionname')
            ->where('optionname', 'like', $prefix . '%'));

        // LIKE takes "_" for any character, so the prefix is checked again as written.
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => stripos((string) $row['optionname'], $prefix) === 0,
        ));
    }

    private static function encodeDisplayName(string $optionName): string
    {
        $parts = explode('|', $optionName, 2);

        return count($parts) === 2 ? $parts[0] . '|' . self::encode($parts[1]) : $optionName;
    }

    /**
     * Encodes HTML's special characters, keeping the entities already there.
     */
    private static function encode(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $values Column => the text it should hold.
     */
    private static function write(string $table, array $row, array $values): void
    {
        $changed = array_filter(
            $values,
            static fn (string $value, string $column): bool => $value !== (string) $row[$column],
            ARRAY_FILTER_USE_BOTH,
        );
        if ($changed !== []) {
            Capsule::table($table)->where('id', (int) $row['id'])->update($changed);
        }
    }
}
