<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Db;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\WhmcsServices;
use WHMCS\Database\Capsule;

final class WhmcsServicesTest extends DatabaseTestCase
{
    private const SERVICE_ID = 101;
    private const TENANT_ID = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('tblhosting')->insert([
            'id' => self::SERVICE_ID,
            'domain' => 'typed.acme.test',
            'username' => '',
        ]);
    }

    #[Test]
    public function itShowsThePortalsTenantIdAndHostnameOnTheService(): void
    {
        WhmcsServices::showPortal(new ServiceLink(self::SERVICE_ID, 1, self::TENANT_ID, 'sign.acme.test'));

        self::assertSame(['username' => self::TENANT_ID, 'domain' => 'sign.acme.test'], self::service());
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function portalsNotInSignNetsForm(): iterable
    {
        yield 'no tenant id' => [null, 'sign.acme.test'];
        yield 'a short tenant id' => ['0f1e2d3c4b5a69788796a5b4c3d2e1f', 'sign.acme.test'];
        yield 'an upper-case tenant id' => [strtoupper(self::TENANT_ID), 'sign.acme.test'];
        yield 'markup in the tenant id' => ['<b>' . self::TENANT_ID . '</b>', 'sign.acme.test'];
        yield 'markup in the hostname' => [self::TENANT_ID, '<b>sign.acme.test</b>'];
        yield 'a quote in the hostname' => [self::TENANT_ID, 'sign.acme.test"'];
        yield 'an upper-case hostname' => [self::TENANT_ID, 'Sign.Acme.test'];
        yield 'no hostname' => [self::TENANT_ID, ''];
    }

    #[Test]
    #[DataProvider('portalsNotInSignNetsForm')]
    public function itLeavesTheServiceAsItWasForAPortalNotInSignNetsForm(?string $tenantId, string $hostname): void
    {
        try {
            WhmcsServices::showPortal(new ServiceLink(self::SERVICE_ID, 1, $tenantId, $hostname));
            self::fail('A portal not in Sign.net\'s form was written to the service.');
        } catch (\UnexpectedValueException $refusal) {
            self::assertStringStartsWith(
                'Sign.net gave this portal a tenant id or address that is not valid',
                $refusal->getMessage(),
            );
        }
        self::assertSame(['username' => '', 'domain' => 'typed.acme.test'], self::service());
    }

    /**
     * @return array{username: string, domain: string}
     */
    private static function service(): array
    {
        $row = Rows::first(Capsule::table('tblhosting')->select('username', 'domain')->where('id', self::SERVICE_ID));

        return ['username' => (string) ($row['username'] ?? ''), 'domain' => (string) ($row['domain'] ?? '')];
    }
}
