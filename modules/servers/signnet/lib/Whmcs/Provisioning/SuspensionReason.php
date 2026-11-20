<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\Whmcs\Support\Utf16;

/**
 * A suspension reason as Sign.net keeps it: at most 500 UTF-16 units, without the whitespace around
 * it, Unicode spaces such as the no-break space included.
 */
final class SuspensionReason
{
    /** Sign.net keeps at most this much of a suspension reason, in UTF-16 units. */
    private const MAX_LENGTH = 500;

    /**
     * WHMCS's reason as the plugin sends it, so that Sign.net keeps it unchanged. Cutting it to length
     * can leave whitespace at its end, which goes too.
     */
    public static function fromWhmcs(string $reason): string
    {
        return self::trimmed(Utf16::truncate(self::trimmed($reason), self::MAX_LENGTH));
    }

    /**
     * $reason without the whitespace around it.
     */
    public static function trimmed(string $reason): string
    {
        return preg_replace('/^[\x00\s\p{Z}\x{FEFF}]+|[\x00\s\p{Z}\x{FEFF}]+$/u', '', $reason) ?? trim($reason);
    }
}
