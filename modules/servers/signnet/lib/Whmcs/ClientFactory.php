<?php

declare(strict_types=1);

namespace SignNet\Whmcs;

use SignNet\ResellerApi\Auth\ApiKey;
use SignNet\ResellerApi\ClientConfig;
use SignNet\ResellerApi\Http\CurlTransport;
use SignNet\ResellerApi\Http\Transport;
use SignNet\ResellerApi\ResellerClient;
use SignNet\ResellerApi\Support\Sleeper;
use SignNet\Whmcs\Db\DbTokenStore;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Support\LoggingTransport;
use WHMCS\Database\Capsule;

/**
 * Builds the API client for a WHMCS server record: Hostname is the Sign.net API host and
 * Password the snk_ API key.
 */
final class ClientFactory
{
    private static ?Transport $testTransport = null;
    private static ?Sleeper $testSleeper = null;

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     *
     * @throws \InvalidArgumentException When the server record holds no usable URL or key.
     */
    public static function fromParams(array $params): ResellerClient
    {
        return self::create(
            self::baseUrl(
                (string) ($params['serverhostname'] ?? ''),
                self::isOn($params['serversecure'] ?? false),
                (string) ($params['serverport'] ?? ''),
            ),
            (string) ($params['serverpassword'] ?? ''),
        );
    }

    /**
     * @throws \InvalidArgumentException When there is no such Sign.net server or it is unusable.
     */
    public static function fromServerId(int $serverId): ResellerClient
    {
        $server = Rows::first(Capsule::table('tblservers')->where('id', $serverId)->where('type', Version::MODULE));
        if ($server === null) {
            throw new \InvalidArgumentException(sprintf('There is no Sign.net server #%d.', $serverId));
        }

        return self::create(
            self::baseUrl((string) $server['hostname'], self::isOn($server['secure']), (string) $server['port']),
            decrypt((string) $server['password']),
        );
    }

    /**
     * Makes every client built afterwards use these instead of the network. Tests only.
     *
     * @internal
     */
    public static function useTestDoubles(?Transport $transport, ?Sleeper $sleeper = null): void
    {
        self::$testTransport = $transport;
        self::$testSleeper = $sleeper;
    }

    private static function create(string $baseUrl, string $apiKey): ResellerClient
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'The Sign.net server has no API key: enter the snk_ key in its Password field.',
            );
        }
        $key = ApiKey::parse($apiKey);
        $config = new ClientConfig($baseUrl, $key, 'SignNet-WHMCS/' . Version::PLUGIN);

        return new ResellerClient(
            $config,
            new LoggingTransport(self::$testTransport ?? new CurlTransport(), $key),
            new DbTokenStore(),
            null,
            self::$testSleeper,
        );
    }

    /**
     * Accepts a bare host ("api-app.sign.net") or a URL someone pasted; an explicit scheme wins
     * over the SSL checkbox, so a local backend can be reached over plain http.
     */
    private static function baseUrl(string $hostname, bool $secure, string $port): string
    {
        $hostname = trim($hostname);
        if ($hostname === '') {
            throw new \InvalidArgumentException('The Sign.net server has no hostname.');
        }
        if (!str_contains($hostname, '://')) {
            $hostname = ($secure ? 'https' : 'http') . '://' . $hostname;
        }
        $port = trim($port);
        $parts = parse_url(rtrim($hostname, '/'));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a usable Sign.net API address.', $hostname));
        }
        $defaultPort = strtolower($parts['scheme']) === 'https' ? '443' : '80';
        $explicitPort = isset($parts['port']) ? (string) $parts['port'] : $port;

        return $parts['scheme'] . '://' . $parts['host']
            . ($explicitPort !== '' && $explicitPort !== $defaultPort ? ':' . $explicitPort : '');
    }

    private static function isOn(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array($value, ['on', '1', 'true', 'yes'], true);
    }
}
