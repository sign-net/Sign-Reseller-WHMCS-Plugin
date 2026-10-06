<?php

/**
 * Sign.net Reseller addon hooks: checkout validation of portal addresses, the welcome email's merge
 * fields, and a warning when a service with a live portal is deleted.
 *
 * WHMCS loads this file on every page while the addon is active; each hook hands over to a class in
 * SignNet\Whmcs\Hooks, and HookGuard keeps a plugin failure from breaking the page.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../servers/signnet/lib/autoload.php';

add_hook('ShoppingCartValidateCheckout', 1, static function (): array {
    return \SignNet\Whmcs\Hooks\HookGuard::run(
        'ShoppingCartValidateCheckout',
        static fn (): array => (new \SignNet\Whmcs\Hooks\CartValidator())->validate(
            $_SESSION['cart']['products'] ?? [],
        ),
        [],
    );
});

add_hook(
    'EmailPreSend',
    1,
    /** @param array<string, mixed> $vars */
    static function (array $vars): array {
        return \SignNet\Whmcs\Hooks\HookGuard::run(
            'EmailPreSend',
            static fn (): array => (new \SignNet\Whmcs\Hooks\WelcomeEmailFields())->mergeFields($vars),
            [],
        );
    },
);

add_hook(
    'ServiceDelete',
    1,
    /** @param array<string, mixed> $vars */
    static function (array $vars): void {
        \SignNet\Whmcs\Hooks\HookGuard::run(
            'ServiceDelete',
            static fn () => (new \SignNet\Whmcs\Hooks\OrphanedPortalFlagger())->flag($vars),
            null,
        );
    },
);
