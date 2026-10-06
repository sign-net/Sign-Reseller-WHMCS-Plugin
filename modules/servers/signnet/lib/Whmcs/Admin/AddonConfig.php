<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Support\Settings;
use SignNet\Whmcs\Version;

/**
 * The addon's entry in WHMCS (System Settings > Addon Modules) and its settings form.
 */
final class AddonConfig
{
    private const URL_FIELDS = [
        'support_url' => [
            'label' => 'Portal support URL',
            'help' => 'Where new portals send their users for help (https://…); blank = Sign.net\'s default.',
        ],
        'website_url' => [
            'label' => 'Portal website URL',
            'help' => 'Your website, linked from new portals (https://…); blank = Sign.net\'s default.',
        ],
    ];

    private const COLOUR_LABELS = [
        'color_primary' => 'Primary colour',
        'color_primary_foreground' => 'Text on primary colour',
        'color_accent' => 'Accent colour',
        'color_accent_foreground' => 'Text on accent colour',
        'color_app_bar_background' => 'App bar background',
        'color_footer_background' => 'Footer background',
    ];

    private const FEATURE_LABELS = [
        'feature_business_registration' => 'Business registration',
        'feature_business_profile' => 'Business profile',
        'feature_domain_selector' => 'Domain selector',
        'feature_points_system' => 'Points system',
        'feature_sign_up_page' => 'Sign-up page',
        'feature_referral_codes' => 'Referral codes',
        'feature_stamps' => 'Stamps',
        'feature_help_videos' => 'Help videos',
        'feature_social_links' => 'Social links',
    ];

    private const FEATURE_CHOICES = [
        Settings::FEATURE_DEFAULT => 'Sign.net default',
        Settings::FEATURE_ON => 'On',
        Settings::FEATURE_OFF => 'Off',
    ];

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     version: string,
     *     author: string,
     *     fields: array<string, array<string, mixed>>,
     * }
     */
    public static function build(): array
    {
        return [
            'name' => 'Sign.net Reseller',
            'description' => 'Manage your Sign.net packages and add-ons, sell them as WHMCS products, and keep an eye '
                . 'on your Sign.net allowance and portals.',
            'version' => Version::PLUGIN,
            'author' => 'Sign.net',
            'fields' => [Settings::DEFAULT_SERVER => self::serverField()]
                + self::urlFields()
                + self::colourFields()
                + self::featureFields()
                + [Settings::LOW_ALLOWANCE_PERCENT => self::lowAllowanceField()],
        ];
    }

    /**
     * The name the settings form shows for a setting.
     */
    public static function label(string $setting): string
    {
        return self::URL_FIELDS[$setting]['label']
            ?? self::COLOUR_LABELS[$setting]
            ?? self::FEATURE_LABELS[$setting]
            ?? $setting;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverField(): array
    {
        return [
            'FriendlyName' => 'Sign.net server',
            'Type' => 'dropdown',
            'Options' => ['' => 'First Sign.net server'] + SignNetServers::active(),
            'Default' => '',
            'Description' => 'Which server (reseller account) the addon\'s pages use.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function urlFields(): array
    {
        $fields = [];
        foreach (array_keys(Settings::URL_SETTINGS) as $setting) {
            $fields[$setting] = self::textField(self::label($setting), self::URL_FIELDS[$setting]['help'], '50');
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function colourFields(): array
    {
        $fields = [];
        foreach (array_keys(Settings::COLOUR_SETTINGS) as $setting) {
            $fields[$setting] = self::textField(self::label($setting), '#rrggbb, blank = Sign.net\'s default', '10');
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function featureFields(): array
    {
        $fields = [];
        foreach (array_keys(Settings::FEATURE_SETTINGS) as $setting) {
            $fields[$setting] = [
                'FriendlyName' => self::label($setting),
                'Type' => 'dropdown',
                'Options' => self::FEATURE_CHOICES,
                'Default' => Settings::FEATURE_DEFAULT,
                'Description' => 'What new portals start with.',
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private static function lowAllowanceField(): array
    {
        return self::textField(
            'Low allowance warning (%)',
            'The dashboard warns when less than this share of an allowance item is left.',
            '5',
        ) + ['Default' => '10'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function textField(string $label, string $description, string $size): array
    {
        return ['FriendlyName' => $label, 'Type' => 'text', 'Size' => $size, 'Description' => $description];
    }
}
