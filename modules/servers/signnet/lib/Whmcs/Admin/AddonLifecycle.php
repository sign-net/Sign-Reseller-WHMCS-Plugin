<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Support\ErrorText;

/**
 * Activating, deactivating and upgrading the addon. Nothing here ever drops a table or a setting,
 * and no failure reaches WHMCS.
 */
final class AddonLifecycle
{
    /**
     * @return array{status: string, description: string}
     */
    public static function activate(): array
    {
        try {
            self::install();
        } catch (\Throwable $error) {
            return [
                'status' => 'error',
                'description' => 'Sign.net Reseller could not set up its tables: ' . ErrorText::describe($error),
            ];
        }

        return [
            'status' => 'success',
            'description' => 'Sign.net Reseller is active. Add your Sign.net account as a server (System '
                . 'Settings > Servers, module Sign.net Private Label, the API host as Hostname and your snk_ key '
                . 'as Password), then open Addons > Sign.net Reseller.',
        ];
    }

    /**
     * @return array{status: string, description: string}
     */
    public static function deactivate(): array
    {
        return [
            'status' => 'success',
            'description' => 'Sign.net Reseller is deactivated. Its portal links, data and settings are kept, so '
                . 'activating it again carries on where it left off.',
        ];
    }

    /**
     * WHMCS runs this once after the plugin's files change version. It has no way to report a failure,
     * so one goes to the activity log; the next activation or upgrade tries again.
     */
    public static function upgrade(): void
    {
        try {
            self::install();
        } catch (\Throwable $error) {
            logActivity('Sign.net Reseller could not upgrade its tables: ' . ErrorText::describe($error));
        }
    }

    /**
     * Creates what is missing of the plugin's tables and the welcome email template; idempotent.
     */
    private static function install(): void
    {
        Schema::install();
        EmailTemplateInstaller::install();
    }
}
