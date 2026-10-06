<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\Whmcs\Support\Utf16;

/**
 * What an order says the portal should be: its address and name, its owner, and how many of
 * each add-on the customer chose.
 *
 * The address comes from the product's "Portal address" custom field, or the service's domain
 * when the product has none; add-ons from Quantity configurable options named
 * "addon_<CODE>|<Display name>".
 */
final class OrderDetails
{
    public const HOST_FIELD = 'Portal address';
    public const HOST_FIELD_KEY = 'portal_host';
    public const NAME_FIELD = 'Portal name';
    public const NAME_FIELD_KEY = 'portal_name';
    public const ADDON_OPTION_PREFIX = 'addon_';

    /** Sign.net's limits, in the UTF-16 units its backend counts. */
    private const MAX_NAME_LENGTH = 50;
    private const MAX_PORTAL_NAME_LENGTH = 255;

    /**
     * @param array<array-key, int> $addonQuantities Add-on code, upper case, => quantity (0 = none).
     *     PHP turns a numeric code into an int key, so read keys back with (string).
     */
    public function __construct(
        public readonly string $hostname,
        public readonly string $portalName,
        public readonly string $ownerEmail,
        public readonly string $ownerFirstName,
        public readonly string $ownerLastName,
        public readonly array $addonQuantities,
    ) {
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     *
     * @throws InvalidOrderException
     */
    public static function fromParams(array $params): self
    {
        $customFields = self::stringMap($params['customfields'] ?? []);
        $client = self::stringMap($params['clientsdetails'] ?? []);

        $hostname = HostnameNormalizer::normalize(
            self::firstFilled([
                $customFields[self::HOST_FIELD_KEY] ?? '',
                $customFields[self::HOST_FIELD] ?? '',
                (string) ($params['domain'] ?? ''),
            ]),
        );
        $firstName = self::name($client['firstname'] ?? '', 'first name');
        $lastName = self::name($client['lastname'] ?? '', 'last name');
        $email = trim($client['email'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidOrderException('The client has no valid email address to own the portal.');
        }

        return new self(
            $hostname,
            Utf16::truncate(self::firstFilled([
                $customFields[self::NAME_FIELD_KEY] ?? '',
                $customFields[self::NAME_FIELD] ?? '',
                $client['companyname'] ?? '',
                $firstName . ' ' . $lastName,
            ]), self::MAX_PORTAL_NAME_LENGTH),
            $email,
            $firstName,
            $lastName,
            self::addonQuantitiesFrom($params),
        );
    }

    public function portalUrl(): string
    {
        return 'https://' . $this->hostname;
    }

    /**
     * The add-on quantities the service's "addon_<CODE>" configurable options hold.
     *
     * @param array<string, mixed> $params WHMCS module parameters.
     *
     * @return array<array-key, int> Add-on code, upper case, => quantity (0 = none).
     */
    public static function addonQuantitiesFrom(array $params): array
    {
        $quantities = [];
        foreach (self::stringMap($params['configoptions'] ?? []) as $option => $value) {
            $name = explode('|', $option, 2)[0];
            if (stripos($name, self::ADDON_OPTION_PREFIX) !== 0) {
                continue;
            }
            $code = strtoupper(trim(substr($name, strlen(self::ADDON_OPTION_PREFIX))));
            if ($code !== '') {
                $quantities[$code] = max(0, (int) $value);
            }
        }

        return $quantities;
    }

    private static function name(string $name, string $which): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidOrderException(sprintf('The client has no %s, which the portal owner needs.', $which));
        }

        return Utf16::truncate($name, self::MAX_NAME_LENGTH);
    }

    /**
     * @param list<string> $candidates
     */
    private static function firstFilled(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * The scalar values of a parameter group, decoded: WHMCS stores what clients type HTML-encoded,
     * and Sign.net should get "Tom & Jerry", not "Tom &amp; Jerry".
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $map = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $map[(string) $key] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $map;
    }
}
