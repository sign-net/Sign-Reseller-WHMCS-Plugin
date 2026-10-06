<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Support;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsSchema;
use SignNet\Whmcs\Support\Settings;

final class SettingsTest extends DatabaseTestCase
{
    #[Test]
    public function itGivesNewPortalsOnlyTheBrandingTheResellerSet(): void
    {
        WhmcsSchema::setAddonSettings([
            'support_url' => 'https://help.reseller.test',
            'website_url' => '',
            'color_primary' => '#0055AA',
            'color_accent' => '',
            'feature_stamps' => 'off',
            'feature_sign_up_page' => 'on',
            'feature_points_system' => 'default',
        ]);

        self::assertSame(
            [
                'supportUrl' => 'https://help.reseller.test',
                'colorPrimary' => '#0055aa',
                'featureSignUpPage' => true,
                'featureStamps' => false,
            ],
            Settings::load()->branding(),
        );
    }

    #[Test]
    public function itLeavesOutAndNamesSettingsThatAreNotAUrlOrAColour(): void
    {
        WhmcsSchema::setAddonSettings([
            'support_url' => 'help.reseller.test',
            'website_url' => 'https://reseller.test/' . str_repeat('a', 500),
            'color_primary' => 'blue',
            'color_footer_background' => '#123',
        ]);
        $settings = Settings::load();

        self::assertSame(['colorFooterBackground' => '#123'], $settings->branding());
        self::assertSame(['support_url', 'website_url', 'color_primary'], $settings->invalidSettings());
    }

    #[Test]
    public function itReadsTheDefaultServerAndTheLowAllowanceThreshold(): void
    {
        self::assertNull(Settings::load()->defaultServerId());
        self::assertSame(10, Settings::load()->lowAllowancePercent());

        WhmcsSchema::setAddonSettings(['default_server' => '4', 'low_allowance_percent' => '25']);

        self::assertSame(4, Settings::load()->defaultServerId());
        self::assertSame(25, Settings::load()->lowAllowancePercent());
    }
}
