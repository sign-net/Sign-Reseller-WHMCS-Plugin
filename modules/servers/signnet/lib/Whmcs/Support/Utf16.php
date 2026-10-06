<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Support;

/**
 * Lengths as Sign.net's JavaScript backend measures them: in UTF-16 code units, where a character
 * outside the Basic Multilingual Plane (most emoji, rarer CJK) counts twice.
 */
final class Utf16
{
    /**
     * $text cut, at a character boundary, to at most $maxUnits UTF-16 code units.
     */
    public static function truncate(string $text, int $maxUnits): string
    {
        $kept = '';
        $units = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            $units += mb_ord($character, 'UTF-8') > 0xFFFF ? 2 : 1;
            if ($units > $maxUnits) {
                break;
            }
            $kept .= $character;
        }

        return $kept;
    }
}
