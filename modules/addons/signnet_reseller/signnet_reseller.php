<?php

/**
 * Sign.net Reseller addon: packages, add-ons and the reseller dashboard.
 *
 * WHMCS calls these functions by name; each hands over to a class in SignNet\Whmcs\Admin.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../servers/signnet/lib/autoload.php';

/**
 * @return array<string, mixed>
 */
function signnet_reseller_config(): array
{
    return \SignNet\Whmcs\Admin\AddonConfig::build();
}

/**
 * @return array{status: string, description: string}
 */
function signnet_reseller_activate(): array
{
    return \SignNet\Whmcs\Admin\AddonLifecycle::activate();
}

/**
 * @return array{status: string, description: string}
 */
function signnet_reseller_deactivate(): array
{
    return \SignNet\Whmcs\Admin\AddonLifecycle::deactivate();
}

/**
 * WHMCS passes the previously installed version; every step is idempotent, so it is not needed.
 */
function signnet_reseller_upgrade(): void
{
    \SignNet\Whmcs\Admin\AddonLifecycle::upgrade();
}

/**
 * @param array<string, mixed> $vars
 */
function signnet_reseller_output(array $vars): void
{
    echo (new \SignNet\Whmcs\Admin\AdminRouter())->render(
        $vars,
        $_GET,
        $_POST,
        is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
    );
}
