<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Db;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\VersionOneTables;
use SignNet\Whmcs\Db\DbTokenStore;
use SignNet\Whmcs\Db\OrphanedLinkException;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use WHMCS\Database\Capsule;

final class SchemaTest extends DatabaseTestCase
{
    private const FINGERPRINT = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';

    #[Test]
    public function itAddsTheSlugToATokenTableFromVersionOneAndKeepsItsToken(): void
    {
        VersionOneTables::createTokenTable();
        Capsule::table(Schema::TOKENS)->insert([
            'fingerprint' => self::FINGERPRINT,
            'access_token' => encrypt('tok-secret'),
            'scopes' => '["reseller:read"]',
            'expires_at' => 2_000_000_000,
            'updated_at' => 1_900_000_000,
        ]);

        Schema::install();
        Schema::install();

        self::assertTrue(Capsule::schema()->hasColumn(Schema::TOKENS, 'slug'));
        $token = (new DbTokenStore())->get(self::FINGERPRINT);
        self::assertSame(['tok-secret', null], [$token?->token, $token?->slug]);
        (new DbTokenStore())->put(self::FINGERPRINT, new AccessToken('tok', [], 2_000_000_000, 'reseller.sign.test'));
        self::assertSame('reseller.sign.test', (new DbTokenStore())->get(self::FINGERPRINT)?->slug);
    }

    #[Test]
    public function itRecordsWhichLinksOfAnEarlierVersionHoldASuspensionWhmcsDidNotMake(): void
    {
        VersionOneTables::createServiceTable();
        Capsule::table('tblhosting')->insert([
            ['id' => 201, 'domainstatus' => 'Suspended'],
            ['id' => 202, 'domainstatus' => 'Active'],
            ['id' => 203, 'domainstatus' => 'Active'],
        ]);
        Capsule::table(Schema::SERVICES)->insert([
            VersionOneTables::serviceRow(201, ServiceLink::STATE_SUSPENDED),
            VersionOneTables::serviceRow(202, ServiceLink::STATE_SUSPENDED),
            VersionOneTables::serviceRow(203, ServiceLink::STATE_ACTIVE),
        ]);

        Schema::install();
        Schema::install();

        self::assertTrue(Capsule::schema()->hasColumn(Schema::SERVICES, 'suspend_attempt_at'));
        self::assertTrue(Capsule::schema()->hasColumn(Schema::SERVICES, 'whmcs_suspended_at'));
        $links = new ServiceRepository();
        self::assertNull($links->find(201)?->suspendedOutsideAt, 'WHMCS suspended the service too.');
        self::assertNotNull($links->find(202)?->suspendedOutsideAt);
        self::assertNull($links->find(203)?->suspendedOutsideAt);
    }

    #[Test]
    public function itRecordsTheOrderOfEachEarlierLinksServiceAndNoneForADeletedService(): void
    {
        VersionOneTables::createServiceTable();
        Capsule::table('tblhosting')->insert([
            ['id' => 301, 'orderid' => 77, 'domainstatus' => 'Active'],
            ['id' => 303, 'orderid' => 0, 'domainstatus' => 'Active'],
            ['id' => 304, 'orderid' => null, 'domainstatus' => 'Active'],
        ]);
        Capsule::table(Schema::SERVICES)->insert([
            VersionOneTables::serviceRow(301, ServiceLink::STATE_ACTIVE),
            VersionOneTables::serviceRow(302, ServiceLink::STATE_ACTIVE),
            VersionOneTables::serviceRow(303, ServiceLink::STATE_ACTIVE),
            VersionOneTables::serviceRow(304, ServiceLink::STATE_ACTIVE),
        ]);

        Schema::install();
        Capsule::table('tblhosting')->insert(['id' => 302, 'orderid' => 88, 'domainstatus' => 'Pending']);
        Schema::install();

        $links = new ServiceRepository();
        self::assertSame(
            [77, ServiceLink::ORDER_OF_DELETED_SERVICE, 0, 0],
            array_map(static fn (int $id): ?int => $links->find($id)?->orderId, [301, 302, 303, 304]),
        );
        self::assertSame([77, 0], [$links->forService(301)?->orderId, $links->forService(304)?->orderId]);
        $this->expectException(OrphanedLinkException::class);
        $links->forService(302);
    }
}
