<?php

declare(strict_types=1);

namespace SignNet\ResellerApi;

use SignNet\ResellerApi\Auth\ApiKey;

final class ClientConfig
{
    public const DEFAULT_USER_AGENT = 'SignNet-ResellerApi-PHP';

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * The API origin, e.g. "https://api-app.sign.net", without a trailing slash.
     */
    public readonly string $baseUrl;

    public readonly ApiKey $apiKey;

    /**
     * @param string $baseUrl HTTPS only; plain HTTP is accepted for localhost and 127.0.0.1.
     * @param int $connectTimeout Seconds allowed to establish the connection.
     * @param int $timeout Seconds allowed for a whole call.
     * @param int $provisionTimeout Seconds allowed for provisioning, which seeds files and
     *     attaches the hostname before it answers.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter]
        ApiKey|string $apiKey,
        public readonly string $userAgent = self::DEFAULT_USER_AGENT,
        public readonly int $connectTimeout = 10,
        public readonly int $timeout = 30,
        public readonly int $provisionTimeout = 90,
    ) {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->apiKey = $apiKey instanceof ApiKey ? $apiKey : ApiKey::parse($apiKey);
        if (trim($userAgent) === '' || preg_match('/[\x00-\x1F\x7F]/', $userAgent) === 1) {
            throw new \InvalidArgumentException('The user agent must be non-empty printable text.');
        }
        if (min($connectTimeout, $timeout, $provisionTimeout) < 1) {
            throw new \InvalidArgumentException('Timeouts must be at least one second.');
        }
    }

    /**
     * The SHA-256 of the API origin and the full key, which tokens and minting pauses are stored
     * under: a token one deployment issued means nothing to another, and a key refused at a wrong
     * address should be tried again as soon as the address is corrected.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->baseUrl . "\n" . $this->apiKey->reveal());
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException(
                'The Sign.net API base URL must be an absolute URL such as https://api-app.sign.net.',
            );
        }
        $scheme = strtolower($parts['scheme']);
        $isLocal = in_array(strtolower($parts['host']), self::LOCAL_HOSTS, true);
        if ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) {
            throw new \InvalidArgumentException(
                'The Sign.net API base URL must use https; plain http is only allowed for localhost.',
            );
        }

        return $baseUrl;
    }
}
