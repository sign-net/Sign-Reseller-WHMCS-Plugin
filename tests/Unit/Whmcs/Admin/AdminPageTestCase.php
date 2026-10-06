<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Whmcs\Admin\AdminRouter;

/**
 * Renders the addon's admin pages the way its output function does, with a fake Sign.net behind them.
 */
abstract class AdminPageTestCase extends ModuleTestCase
{
    protected const MODULE_LINK = 'addonmodules.php?module=signnet_reseller';

    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
        WhmcsCatalogueSchema::insertCurrency('USD', true);
    }

    /**
     * @param array<string, string> $query
     */
    protected function get(array $query): string
    {
        return (new AdminRouter())->render(['modulelink' => self::MODULE_LINK], $query, [], 'GET');
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $form
     */
    protected function post(array $query, array $form): string
    {
        return (new AdminRouter())->render(
            ['modulelink' => self::MODULE_LINK],
            $query,
            $form + ['token' => WhmcsFake::TOKEN],
            'POST',
        );
    }
}
