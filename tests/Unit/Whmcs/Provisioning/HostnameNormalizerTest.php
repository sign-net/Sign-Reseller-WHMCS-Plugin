<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\Whmcs\Provisioning\HostnameNormalizer;
use SignNet\Whmcs\Provisioning\InvalidOrderException;

final class HostnameNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedAddresses(): iterable
    {
        yield 'plain host' => ['sign.acme.com', 'sign.acme.com'];
        yield 'upper case and spaces' => ['  Sign.ACME.com ', 'sign.acme.com'];
        yield 'pasted URL' => ['https://sign.acme.com:443/login?next=1', 'sign.acme.com'];
        yield 'trailing dot' => ['sign.acme.com.', 'sign.acme.com'];
        yield 'apex domain' => ['acme.com', 'acme.com'];
        yield 'internationalised' => ['portal.bücher.de', 'portal.xn--bcher-kva.de'];
        yield 'hyphens inside labels' => ['e-sign.my-company.co.uk', 'e-sign.my-company.co.uk'];
    }

    #[Test]
    #[DataProvider('acceptedAddresses')]
    public function itStoresTheHostnameSignNetWouldStore(string $typed, string $stored): void
    {
        self::assertSame($stored, HostnameNormalizer::normalize($typed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'single label' => ['acme'];
        yield 'email address' => ['ada@acme.com'];
        yield 'IP address' => ['192.168.1.10'];
        yield 'underscore' => ['my_portal.acme.com'];
        yield 'leading hyphen' => ['-sign.acme.com'];
        yield 'numeric top level' => ['sign.acme.123'];
        yield 'label too long' => [str_repeat('a', 64) . '.acme.com'];
        yield 'name too long' => [str_repeat(str_repeat('a', 60) . '.', 5) . 'com'];
    }

    #[Test]
    #[DataProvider('refusedAddresses')]
    public function itRefusesWhatSignNetWouldRefuse(string $typed): void
    {
        $this->expectException(InvalidOrderException::class);

        HostnameNormalizer::normalize($typed);
    }
}
