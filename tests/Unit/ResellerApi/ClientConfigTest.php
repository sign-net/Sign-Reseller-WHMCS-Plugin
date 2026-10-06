<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Auth\ApiKey;
use SignNet\ResellerApi\ClientConfig;

final class ClientConfigTest extends TestCase
{
    private const API_KEY = 'snk_live_0123456789abcdef0123456789abcdef_Secret';

    #[Test]
    public function itAppliesTheDocumentedDefaults(): void
    {
        $config = new ClientConfig('https://api-app.sign.net', self::API_KEY);

        self::assertSame('https://api-app.sign.net', $config->baseUrl);
        self::assertSame(ClientConfig::DEFAULT_USER_AGENT, $config->userAgent);
        self::assertSame('SignNet-ResellerApi-PHP', $config->userAgent);
        self::assertSame(10, $config->connectTimeout);
        self::assertSame(30, $config->timeout);
        self::assertSame(90, $config->provisionTimeout);
    }

    #[Test]
    public function itParsesAKeyGivenAsTextAndKeepsAParsedOne(): void
    {
        $parsed = ApiKey::parse(self::API_KEY);

        self::assertSame(self::API_KEY, (new ClientConfig('https://api.test', self::API_KEY))->apiKey->reveal());
        self::assertSame($parsed, (new ClientConfig('https://api.test', $parsed))->apiKey);
    }

    #[Test]
    public function itFingerprintsTheAddressAndTheKeyTogether(): void
    {
        $fingerprint = (new ClientConfig('https://api-app.sign.net', self::API_KEY))->fingerprint();

        self::assertSame(hash('sha256', "https://api-app.sign.net\n" . self::API_KEY), $fingerprint);
        self::assertSame($fingerprint, (new ClientConfig('https://api-app.sign.net/', self::API_KEY))->fingerprint());
        self::assertNotSame($fingerprint, (new ClientConfig('https://api.test', self::API_KEY))->fingerprint());
        self::assertNotSame(
            $fingerprint,
            (new ClientConfig('https://api-app.sign.net', self::API_KEY . 'X'))->fingerprint(),
        );
    }

    #[Test]
    public function itRefusesAMalformedKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientConfig('https://api.test', 'not-a-key');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedBaseUrls(): iterable
    {
        yield 'trailing slash' => ['https://api-app.sign.net/', 'https://api-app.sign.net'];
        yield 'with path' => ['https://example.com/backend//', 'https://example.com/backend'];
        yield 'with port' => ['https://example.com:8443', 'https://example.com:8443'];
        yield 'localhost over http' => ['http://localhost:3001', 'http://localhost:3001'];
        yield 'loopback over http' => ['http://127.0.0.1:8080/', 'http://127.0.0.1:8080'];
    }

    #[Test]
    #[DataProvider('acceptedBaseUrls')]
    public function itNormalisesAnAcceptedBaseUrl(string $given, string $expected): void
    {
        self::assertSame($expected, (new ClientConfig($given, self::API_KEY))->baseUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedBaseUrls(): iterable
    {
        yield 'plain http' => ['http://api-app.sign.net'];
        yield 'no scheme' => ['api-app.sign.net'];
        yield 'other scheme' => ['ftp://api-app.sign.net'];
        yield 'credentials' => ['https://user:pass@api-app.sign.net'];
        yield 'query' => ['https://api-app.sign.net?x=1'];
        yield 'fragment' => ['https://api-app.sign.net#top'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('refusedBaseUrls')]
    public function itRefusesABaseUrlThatIsNotAbsoluteHttps(string $baseUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientConfig($baseUrl, self::API_KEY);
    }

    #[Test]
    public function itRefusesAUserAgentThatCouldInjectHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientConfig('https://api.test', self::API_KEY, "Agent\r\nX-Evil: 1");
    }

    #[Test]
    public function itRefusesATimeoutBelowOneSecond(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientConfig('https://api.test', self::API_KEY, timeout: 0);
    }
}
