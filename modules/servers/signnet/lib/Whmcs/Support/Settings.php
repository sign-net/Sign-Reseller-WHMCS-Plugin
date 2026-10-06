<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Support;

use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Version;
use WHMCS\Database\Capsule;

/**
 * The Sign.net Reseller addon's settings, as saved on WHMCS's addon configuration page.
 */
final class Settings
{
    public const DEFAULT_SERVER = 'default_server';
    public const LOW_ALLOWANCE_PERCENT = 'low_allowance_percent';

    public const FEATURE_DEFAULT = 'default';
    public const FEATURE_ON = 'on';
    public const FEATURE_OFF = 'off';

    /** Setting name => the portal config key it fills when a portal is created. */
    public const URL_SETTINGS = [
        'support_url' => 'supportUrl',
        'website_url' => 'websiteUrl',
    ];

    public const COLOUR_SETTINGS = [
        'color_primary' => 'colorPrimary',
        'color_primary_foreground' => 'colorPrimaryForeground',
        'color_accent' => 'colorAccent',
        'color_accent_foreground' => 'colorAccentForeground',
        'color_app_bar_background' => 'colorAppBarBackground',
        'color_footer_background' => 'colorFooterBackground',
    ];

    public const FEATURE_SETTINGS = [
        'feature_business_registration' => 'featureBusinessRegistration',
        'feature_business_profile' => 'featureBusinessProfile',
        'feature_domain_selector' => 'featureDomainSelector',
        'feature_points_system' => 'featurePointsSystem',
        'feature_sign_up_page' => 'featureSignUpPage',
        'feature_referral_codes' => 'featureReferralCodes',
        'feature_stamps' => 'featureStamps',
        'feature_help_videos' => 'featureHelpVideos',
        'feature_social_links' => 'featureSocialLinks',
    ];

    private const DEFAULT_LOW_ALLOWANCE_PERCENT = 10;

    /** Sign.net's limit on a portal's support and website URLs, in characters. */
    private const MAX_URL_LENGTH = 500;

    /** The backend stores colours as #rgb or #rrggbb. */
    private const COLOUR_PATTERN = '/^#([0-9a-f]{3}|[0-9a-f]{6})$/iD';

    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(): self
    {
        $values = [];
        foreach (Rows::all(Capsule::table('tbladdonmodules')->where('module', Version::ADDON)) as $row) {
            $values[(string) $row['setting']] = (string) $row['value'];
        }

        return new self($values);
    }

    public function defaultServerId(): ?int
    {
        $serverId = (int) $this->value(self::DEFAULT_SERVER);

        return $serverId > 0 ? $serverId : null;
    }

    /**
     * Below this share of an item's allowance left, the dashboard warns.
     */
    public function lowAllowancePercent(): int
    {
        $value = $this->value(self::LOW_ALLOWANCE_PERCENT);
        if ($value === '' || !ctype_digit($value)) {
            return self::DEFAULT_LOW_ALLOWANCE_PERCENT;
        }

        return min(100, (int) $value);
    }

    /**
     * The portal config a new portal starts with: only what the reseller set, so everything
     * else keeps Sign.net's defaults. Settings that are not a valid URL or colour are left out
     * (invalidSettings() names them) rather than failing every order.
     *
     * @return array<string, string|bool>
     */
    public function branding(): array
    {
        $branding = [];
        foreach (self::URL_SETTINGS as $setting => $key) {
            $url = $this->value($setting);
            if ($url !== '' && self::isWebUrl($url)) {
                $branding[$key] = $url;
            }
        }
        foreach (self::COLOUR_SETTINGS as $setting => $key) {
            $colour = $this->value($setting);
            if (preg_match(self::COLOUR_PATTERN, $colour) === 1) {
                $branding[$key] = strtolower($colour);
            }
        }
        foreach (self::FEATURE_SETTINGS as $setting => $key) {
            $choice = $this->value($setting);
            if ($choice === self::FEATURE_ON || $choice === self::FEATURE_OFF) {
                $branding[$key] = $choice === self::FEATURE_ON;
            }
        }

        return $branding;
    }

    /**
     * Setting names whose value branding() ignores because it is not a URL or a colour.
     *
     * @return list<string>
     */
    public function invalidSettings(): array
    {
        $invalid = [];
        foreach (array_keys(self::URL_SETTINGS) as $setting) {
            $url = $this->value($setting);
            if ($url !== '' && !self::isWebUrl($url)) {
                $invalid[] = $setting;
            }
        }
        foreach (array_keys(self::COLOUR_SETTINGS) as $setting) {
            $colour = $this->value($setting);
            if ($colour !== '' && preg_match(self::COLOUR_PATTERN, $colour) !== 1) {
                $invalid[] = $setting;
            }
        }

        return $invalid;
    }

    private function value(string $setting): string
    {
        return trim($this->values[$setting] ?? '');
    }

    private static function isWebUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($scheme === 'https' || $scheme === 'http')
            && mb_strlen($url, 'UTF-8') <= self::MAX_URL_LENGTH;
    }
}
