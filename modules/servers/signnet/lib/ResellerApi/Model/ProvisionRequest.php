<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Assert;

/**
 * A private label to provision. Given a $packageId, Sign.net assigns that package to it straight
 * after creating it; over the allowance it only asks for confirmation, which then goes to
 * ResellerClient::assignPackage() for the new tenant.
 *
 * $branding holds the optional portal config keys, camelCase as the backend names them, e.g.
 * ['colorPrimary' => '#0055aa', 'featurePointsSystem' => false, 'socialLinks' => [['name' =>
 * 'LinkedIn', 'url' => 'https://...', 'icon' => 'LinkedIn']]]. A null value means "use the backend
 * default" and is left out of the request, as is every key not given.
 */
final class ProvisionRequest
{
    private const TYPE_STRING = 'string';
    private const TYPE_BOOLEAN = 'boolean';
    private const TYPE_SOCIAL_LINKS = 'social links';

    private const BRANDING_TYPES = [
        'logoAlt' => self::TYPE_STRING,
        'favicon' => self::TYPE_STRING,
        'copyrightHolder' => self::TYPE_STRING,
        'supportUrl' => self::TYPE_STRING,
        'websiteUrl' => self::TYPE_STRING,
        'socialLinks' => self::TYPE_SOCIAL_LINKS,
        'colorPrimary' => self::TYPE_STRING,
        'colorPrimaryForeground' => self::TYPE_STRING,
        'colorAccent' => self::TYPE_STRING,
        'colorAccentForeground' => self::TYPE_STRING,
        'colorAppBarBackground' => self::TYPE_STRING,
        'colorFooterBackground' => self::TYPE_STRING,
        'featureBusinessRegistration' => self::TYPE_BOOLEAN,
        'featureBusinessProfile' => self::TYPE_BOOLEAN,
        'featureDomainSelector' => self::TYPE_BOOLEAN,
        'featurePointsSystem' => self::TYPE_BOOLEAN,
        'featureSignUpPage' => self::TYPE_BOOLEAN,
        'featureReferralCodes' => self::TYPE_BOOLEAN,
        'featureStamps' => self::TYPE_BOOLEAN,
        'featureHelpVideos' => self::TYPE_BOOLEAN,
        'featureSocialLinks' => self::TYPE_BOOLEAN,
    ];

    private const SOCIAL_LINK_FIELDS = ['name', 'url', 'icon'];

    /**
     * @var array<string, mixed> The given branding without nulls, in the backend's key order.
     */
    public readonly array $branding;

    /**
     * @param array<string, mixed> $branding
     *
     * @throws \InvalidArgumentException On a blank required value, an unknown branding key or a
     *     branding value of the wrong type.
     */
    public function __construct(
        public readonly string $ownerEmail,
        public readonly string $ownerFirstName,
        public readonly string $ownerLastName,
        public readonly string $domain,
        public readonly string $appName,
        array $branding = [],
        public readonly ?string $packageId = null,
    ) {
        Assert::notBlank('owner email', $ownerEmail);
        Assert::notBlank('owner first name', $ownerFirstName);
        Assert::notBlank('owner last name', $ownerLastName);
        Assert::notBlank('domain', $domain);
        Assert::notBlank('app name', $appName);
        if ($packageId !== null) {
            Assert::notBlank('package id', $packageId);
        }
        $this->branding = self::normalizeBranding($branding);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'owner' => [
                'email' => $this->ownerEmail,
                'firstName' => $this->ownerFirstName,
                'lastName' => $this->ownerLastName,
            ],
            'config' => ['domain' => $this->domain, 'appName' => $this->appName] + $this->branding,
        ];
        if ($this->packageId !== null) {
            $body['package'] = ['packageId' => $this->packageId];
        }

        return $body;
    }

    /**
     * @param array<mixed> $branding
     *
     * @return array<string, mixed>
     */
    private static function normalizeBranding(array $branding): array
    {
        $unknownKeys = array_diff(array_map('strval', array_keys($branding)), array_keys(self::BRANDING_TYPES));
        if ($unknownKeys !== []) {
            throw new \InvalidArgumentException(
                sprintf('Unknown branding keys: %s.', implode(', ', $unknownKeys)),
            );
        }

        $normalized = [];
        foreach (self::BRANDING_TYPES as $key => $type) {
            $value = $branding[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $normalized[$key] = match ($type) {
                self::TYPE_STRING => self::requireString($key, $value),
                self::TYPE_BOOLEAN => self::requireBoolean($key, $value),
                self::TYPE_SOCIAL_LINKS => self::requireSocialLinks($key, $value),
            };
        }

        return $normalized;
    }

    private static function requireString(string $key, mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('The branding value "%s" must be a string.', $key));
        }

        return $value;
    }

    private static function requireBoolean(string $key, mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('The branding value "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @return list<array{name: string, url: string, icon: string}>
     */
    private static function requireSocialLinks(string $key, mixed $value): array
    {
        $error = sprintf(
            'The branding value "%s" must be a list of links with exactly the string fields %s.',
            $key,
            implode(', ', self::SOCIAL_LINK_FIELDS),
        );
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException($error);
        }

        $links = [];
        foreach ($value as $link) {
            if (
                !is_array($link)
                || array_diff(array_keys($link), self::SOCIAL_LINK_FIELDS) !== []
                || !is_string($link['name'] ?? null)
                || !is_string($link['url'] ?? null)
                || !is_string($link['icon'] ?? null)
            ) {
                throw new \InvalidArgumentException($error);
            }
            $links[] = ['name' => $link['name'], 'url' => $link['url'], 'icon' => $link['icon']];
        }

        return $links;
    }
}
