<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

/**
 * Sign.net's cap on a reseller's packages, and separately on its add-ons. Archived entries still
 * count, and their codes stay taken for good.
 */
final class CatalogueLimit
{
    public const MAX_ENTRIES = 100;

    public static function isFull(int $total): bool
    {
        return $total >= self::MAX_ENTRIES;
    }

    /**
     * "12 of 100 packages used, 3 of them archived. …"
     */
    public static function describe(int $total, int $archived, string $plural): string
    {
        return sprintf(
            '%d of %d %s used, %d of them archived. Archived %s still count, and their codes can never be used again.',
            $total,
            self::MAX_ENTRIES,
            $plural,
            $archived,
            $plural,
        );
    }
}
