<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Catalogue\PriceMapper;

/**
 * Reads the numbers typed into the addon's forms. Only the shape is checked here; what the numbers
 * may be is Sign.net's to say.
 */
final class FormNumbers
{
    /**
     * @throws \InvalidArgumentException Naming the field, when the text is not a whole number.
     */
    public static function wholeNumber(string $label, string $text): int
    {
        if (preg_match('/^\d{1,9}$/D', $text) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a whole number, 0 or more.', $label));
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
