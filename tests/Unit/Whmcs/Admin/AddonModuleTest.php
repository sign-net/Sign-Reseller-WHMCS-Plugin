<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Support\WhmcsSchema;
use SignNet\Whmcs\Admin\EmailTemplateInstaller;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Support\Settings;
use SignNet\Whmcs\Version;
use WHMCS\Database\Capsule;

/**
 * The addon's signnet_reseller_* functions, as WHMCS calls them.
 */
final class AddonModuleTest extends DatabaseTestCase
{
    private const API_KEY = 'snk_test_0123456789abcdef0123456789abcdef_S3cr3t';

    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
        require_once dirname(__DIR__, 4) . '/modules/addons/signnet_reseller/signnet_reseller.php';
    }

    #[Test]
    public function itDescribesItselfAndEverySettingToWhmcs(): void
    {
        $config = signnet_reseller_config();

        self::assertSame(['Sign.net Reseller', Version::PLUGIN, 'Sign.net'], [
            $config['name'],
            $config['version'],
            $config['author'],
        ]);
        self::assertIsArray($config['fields']);
        self::assertSame(
            [
                Settings::DEFAULT_SERVER,
                ...array_keys(Settings::URL_SETTINGS),
                ...array_keys(Settings::COLOUR_SETTINGS),
                ...array_keys(Settings::FEATURE_SETTINGS),
                Settings::LOW_ALLOWANCE_PERCENT,
            ],
            array_keys($config['fields']),
        );
        $stamps = $config['fields']['feature_stamps'];
        self::assertSame('dropdown', $stamps['Type']);
        self::assertSame(['default' => 'Sign.net default', 'on' => 'On', 'off' => 'Off'], $stamps['Options']);
        self::assertSame('default', $stamps['Default']);
        self::assertSame('10', $config['fields'][Settings::LOW_ALLOWANCE_PERCENT]['Default']);
    }

    #[Test]
    public function itOffersTheActiveSignNetServersAsTheDefaultServer(): void
    {
        $active = WhmcsSchema::insertServer(self::API_KEY);
        $disabled = WhmcsSchema::insertServer(self::API_KEY);
        $cpanel = WhmcsSchema::insertServer(self::API_KEY);
        Capsule::table('tblservers')->where('id', $disabled)->update(['disabled' => 1]);
        Capsule::table('tblservers')->where('id', $cpanel)->update(['type' => 'cpanel']);

        $field = signnet_reseller_config()['fields'][Settings::DEFAULT_SERVER];

        self::assertSame(['' => 'First Sign.net server', $active => 'Sign.net'], $field['Options']);
    }

    #[Test]
    public function itCreatesItsTablesAndTheWelcomeEmailOnActivationOnlyOnce(): void
    {
        foreach ([Schema::SERVICES, Schema::TOKENS, Schema::CACHE] as $table) {
            Capsule::schema()->drop($table);
        }

        $first = signnet_reseller_activate();
        $second = signnet_reseller_activate();

        self::assertSame('success', $first['status']);
        self::assertSame('success', $second['status']);
        self::assertTrue(Capsule::schema()->hasTable(Schema::SERVICES));
        self::assertTrue(Capsule::schema()->hasTable(Schema::TOKENS));
        self::assertTrue(Capsule::schema()->hasTable(Schema::CACHE));
        self::assertSame(1, Capsule::table('tblemailtemplates')->count());
    }

    #[Test]
    public function itInstallsAProductWelcomeEmailExplainingThePortal(): void
    {
        signnet_reseller_activate();

        $template = Rows::first(Capsule::table('tblemailtemplates')->where('id', EmailTemplateInstaller::findId()));
        self::assertNotNull($template);
        self::assertSame(
            ['product', 'Sign.net Portal Welcome', 'Your Sign.net portal is ready'],
            [$template['type'], $template['name'], $template['subject']],
        );
        foreach (['{$client_name}', '{$signnet_portal_url}', '{$signnet_dns_records}', '24 hours'] as $expected) {
            self::assertStringContainsString($expected, (string) $template['message']);
        }
    }

    #[Test]
    public function itUpgradesWithoutDuplicatingTheWelcomeEmail(): void
    {
        signnet_reseller_activate();
        $templateId = EmailTemplateInstaller::findId();

        signnet_reseller_upgrade();
        signnet_reseller_upgrade();

        self::assertSame(1, Capsule::table('tblemailtemplates')->count());
        self::assertSame($templateId, EmailTemplateInstaller::findId());
    }

    #[Test]
    public function itReportsAnActivationItCouldNotComplete(): void
    {
        Capsule::schema()->drop('tblemailtemplates');

        $result = signnet_reseller_activate();

        self::assertSame('error', $result['status']);
        self::assertStringStartsWith('Sign.net Reseller could not set up its tables: ', $result['description']);
    }

    #[Test]
    public function itLogsAnUpgradeItCouldNotComplete(): void
    {
        Capsule::schema()->drop('tblemailtemplates');

        signnet_reseller_upgrade();

        self::assertCount(1, WhmcsFake::activityMessages());
        self::assertStringStartsWith(
            'Sign.net Reseller could not upgrade its tables: ',
            WhmcsFake::activityMessages()[0],
        );
    }

    #[Test]
    public function itKeepsItsDataWhenDeactivated(): void
    {
        $result = signnet_reseller_deactivate();

        self::assertSame('success', $result['status']);
        self::assertStringContainsString('kept', $result['description']);
        self::assertTrue(Capsule::schema()->hasTable(Schema::SERVICES));
    }
}
