<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

/**
 * Turns what a customer typed as their portal address into the hostname Sign.net stores, and
 * refuses what Sign.net would refuse, by the same rules Sign.net applies.
 */
final class HostnameNormalizer
{
    private const MAX_LENGTH = 253;
    private const MAX_LABEL_LENGTH = 63;
    private const LABEL = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D';
    /** An alphabetic top-level label, which also rules out a bare IP address. */
    private const TOP_LEVEL_LABEL = '/^[a-z]{2,}$/D';

    /**
     * "https://Portal.Example.com:443/login" becomes "portal.example.com"; an internationalised
     * name becomes its xn-- form.
     *
     * @throws InvalidOrderException
     */
    public static function normalize(string $input): string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $host);
        $host = explode('/', strtr($host, ['?' => '/', '#' => '/']), 2)[0];
        $host = (string) preg_replace('~:\d*$~', '', $host);
        $host = rtrim($host, '.');
        if ($host === '') {
            throw new InvalidOrderException('Enter the portal address, for example sign.example.com.');
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $host = self::toAscii($host, $input);
        }
        if (!self::isValid($host)) {
            throw new InvalidOrderException(sprintf(
                '"%s" is not a valid portal address. Use a hostname such as sign.example.com: letters, digits '
                . 'and hyphens, with at least one dot.',
                trim($input),
            ));
        }

        return $host;
    }

    public static function isValid(string $host): bool
    {
        if ($host === '' || strlen($host) > self::MAX_LENGTH) {
            return false;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH || preg_match(self::LABEL, $label) !== 1) {
                return false;
            }
        }

        return preg_match(self::TOP_LEVEL_LABEL, $labels[count($labels) - 1]) === 1;
    }

    private static function toAscii(string $host, string $input): string
    {
        if (!function_exists('idn_to_ascii')) {
            throw new InvalidOrderException(sprintf(
                '"%s" contains non-ASCII characters. Enter its xn-- form instead.',
                trim($input),
            ));
        }
        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) {
            throw new InvalidOrderException(sprintf('"%s" is not a valid portal address.', trim($input)));
        }

        return strtolower($ascii);
    }
}
