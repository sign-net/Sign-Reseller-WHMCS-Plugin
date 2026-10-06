<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use Illuminate\Database\Query\Builder;

/**
 * Query results as arrays keyed by column name, which is how the plugin reads them.
 */
final class Rows
{
    /**
     * @return array<string, mixed>|null
     */
    public static function first(Builder $query): ?array
    {
        $row = $query->first();

        return is_object($row) ? get_object_vars($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(Builder $query): array
    {
        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = get_object_vars($row);
        }

        return $rows;
    }
}
