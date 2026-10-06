<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Hooks;

use SignNet\Whmcs\Support\ErrorText;

/**
 * Runs a hook so that a failure in the plugin never breaks the checkout, email or deletion that
 * fired it: the failure is written to WHMCS's activity log and the hook answers its fallback.
 */
final class HookGuard
{
    /**
     * @template T
     *
     * @param callable(): T $work
     * @param T $fallback
     *
     * @return T
     */
    public static function run(string $hook, callable $work, mixed $fallback): mixed
    {
        try {
            return $work();
        } catch (\Throwable $error) {
            logActivity(sprintf(
                'Sign.net: the %s hook failed and was skipped: %s',
                $hook,
                ErrorText::describe($error),
            ));

            return $fallback;
        }
    }
}
