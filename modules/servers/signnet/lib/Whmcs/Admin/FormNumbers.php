<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Catalogue\PriceMapper;

/**
 * Reads the numbers typed into the addon's forms. Only their shape, and the least a field takes, are
 * checked here; what Sign.net accepts beyond that is Sign.net's to say.
 */
final class FormNumbers
{
    /**
     * @throws \InvalidArgumentException Naming the field, when the text is not a whole number of at
     *     least $minimum.
     */
    public static function wholeNumber(string $label, string $text, int $minimum = 0): int
    {
        if (preg_match('/^\d{1,9}$/D', $text) !== 1 || (int) $text < $minimum) {
            throw new \InvalidArgumentException(
                sprintf('%s must be a whole number, %d or more.', $label, $minimum),
            );
        }

        return (int) $text;
    }

    /**
     * @return int The amount in minor units.
     *
     * @throws \InvalidArgumentException Naming the field, when the text is not an amount.
     */
    public static function price(string $label, string $text): int
    {
        return PriceMapper::toMinor($text)
            ?? throw new \InvalidArgumentException(sprintf('%s must be an amount such as 19.90.', $label));
    }
}
