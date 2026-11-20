<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Support;

/**
 * Who asked WHMCS to run this request's module call.
 */
final class RequestOrigin
{
    /**
     * Whether an administrator asked for it in WHMCS's admin area, rather than WHMCS's automation:
     * the cron, or a payment made in the client area or reported by a payment gateway. A payment an
     * administrator records in the admin area counts as theirs.
     */
    public static function isAdministrator(): bool
    {
        return defined('ADMINAREA') && PHP_SAPI !== 'cli';
    }
}
