<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Auth\ApiKey;

final class ApiKeyTest extends TestCase
{
    private const KEY_ID = '0123456789abcdef0123456789abcdef';
    private const SECRET = 'Zx9SecretPart00AbC';
    private const LIVE_KEY = 'snk_live_' . self::KEY_ID . '_' . self::SECRET;

    #[Test]
    public function itReadsTheEnvironmentAndKeyIdOfALiveKey(): void
    {
        $key = ApiKey::parse(self::LIVE_KEY);

        self::assertSame('live', $key->environment);
        self::assertSame(self::KEY_ID, $key->keyId);
        self::assertSame(self::LIVE_KEY, $key->reveal());
    }

    #[Test]
    public function itReadsATestKey(): void
    {
        $key = ApiKey::parse('snk_test_' . self::KEY_ID . '_abc123');

        self::assertSame('test', $key->environment);
    }

    #[Test]
    public function itIgnoresSurroundingWhitespaceFromACopiedKey(): void
    {
        $key = ApiKey::parse("  " . self::LIVE_KEY . "\n");

        self::assertSame(self::LIVE_KEY, $key->reveal());
    }

    #[Test]
    public function itNeverShowsTheSecretWhenConvertedOrDumped(): void
    {
        $key = ApiKey::parse(self::LIVE_KEY);

        self::assertSame('snk_live_' . self::KEY_ID . '_****', (string) $key);
        self::assertSame((string) $key, $key->masked());
        self::assertStringNotContainsString(self::SECRET, print_r($key, true));
        ob_start();
        var_dump($key);
        self::assertStringNotContainsString(self::SECRET, (string) ob_get_clean());
        self::assertStringNotContainsString(self::SECRET, json_encode($key, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong prefix' => ['sk_live_' . self::KEY_ID . '_secret'];
        yield 'unknown environment' => ['snk_prod_' . self::KEY_ID . '_secret'];
        yield 'short key id' => ['snk_live_0123456789abcdef_secret'];
        yield 'upper-case key id' => ['snk_live_0123456789ABCDEF0123456789ABCDEF_secret'];
        yield 'missing secret' => ['snk_live_' . self::KEY_ID . '_'];
        yield 'extra segment' => ['snk_live_' . self::KEY_ID . '_secret_more'];
        yield 'secret outside base62' => ['snk_live_' . self::KEY_ID . '_sec-ret'];
        yield 'whitespace inside' => ['snk_live_' . self::KEY_ID . '_sec ret'];
    }

    #[Test]
    #[DataProvider('malformedKeys')]
    public function itRefusesAMalformedKeyWithoutEchoingIt(string $malformed): void
    {
        try {
            ApiKey::parse($malformed);
            self::fail('A malformed key was accepted.');
        } catch (\InvalidArgumentException $exception) {
            if ($malformed !== '') {
                self::assertStringNotContainsString($malformed, $exception->getMessage());
            }
            self::assertStringNotContainsString(self::KEY_ID, $exception->getMessage());
        }
    }
}
