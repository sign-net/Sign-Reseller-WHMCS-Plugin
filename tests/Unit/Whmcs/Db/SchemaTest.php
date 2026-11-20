<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Db;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\Tests\Support\DatabaseTestCase;
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
        self::createTokenTableOfVersionOne();
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
        self::createServiceTableOfVersionOne();
        Capsule::table('tblhosting')->insert([
            ['id' => 201, 'domainstatus' => 'Suspended'],
            ['id' => 202, 'domainstatus' => 'Active'],
            ['id' => 203, 'domainstatus' => 'Active'],
        ]);
        Capsule::table(Schema::SERVICES)->insert([
            self::serviceRowOfVersionOne(201, ServiceLink::STATE_SUSPENDED),
            self::serviceRowOfVersionOne(202, ServiceLink::STATE_SUSPENDED),
            self::serviceRowOfVersionOne(203, ServiceLink::STATE_ACTIVE),
        ]);

        Schema::install();
        Schema::install();

        $links = new ServiceRepository();
        self::assertNull($links->find(201)?->suspendedOutsideAt, 'WHMCS suspended the service too.');
        self::assertNotNull($links->find(202)?->suspendedOutsideAt);
        self::assertNull($links->find(203)?->suspendedOutsideAt);
    }

    #[Test]
    public function itRecordsTheOrderOfEachEarlierLinksServiceAndNoneForADeletedService(): void
    {
        self::createServiceTableOfVersionOne();
        Capsule::table('tblhosting')->insert([
            ['id' => 301, 'orderid' => 77, 'domainstatus' => 'Active'],
            ['id' => 303, 'orderid' => 0, 'domainstatus' => 'Active'],
        ]);
        Capsule::table(Schema::SERVICES)->insert([
            self::serviceRowOfVersionOne(301, ServiceLink::STATE_ACTIVE),
            self::serviceRowOfVersionOne(302, ServiceLink::STATE_ACTIVE),
            self::serviceRowOfVersionOne(303, ServiceLink::STATE_ACTIVE),
        ]);

        Schema::install();
        Capsule::table('tblhosting')->insert(['id' => 302, 'orderid' => 88, 'domainstatus' => 'Pending']);
        Schema::install();

        $links = new ServiceRepository();
        self::assertSame(
            [77, ServiceLink::ORDER_OF_DELETED_SERVICE, 0],
            [$links->find(301)?->orderId, $links->find(302)?->orderId, $links->find(303)?->orderId],
        );
        self::assertSame(77, $links->forService(301)?->orderId);
        $this->expectException(OrphanedLinkException::class);
        $links->forService(302);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceRowOfVersionOne(int $serviceId, string $state): array
    {
        return [
            'service_id' => $serviceId,
            'server_id' => 1,
            'tenant_id' => sprintf('%032d', $serviceId),
            'hostname' => 'portal' . $serviceId . '.acme.test',
            'state' => $state,
            'created_at' => 1_900_000_000,
            'updated_at' => 1_900_000_000,
        ];
    }

    private static function createServiceTableOfVersionOne(): void
    {
        Capsule::schema()->drop(Schema::SERVICES);
        Capsule::schema()->create(Schema::SERVICES, static function (Blueprint $table): void {
            $table->unsignedInteger('service_id')->primary();
            $table->unsignedInteger('server_id');
            $table->string('tenant_id', 32)->nullable()->unique();
            $table->string('hostname', 253)->default('');
            $table->string('portal_name', 255)->default('');
            $table->string('owner_email', 255)->default('');
            $table->string('state', 16)->default(ServiceLink::STATE_PENDING);
            $table->string('attempt_state', 16)->default(ServiceLink::ATTEMPT_NONE);
            $table->unsignedInteger('attempt_at')->nullable();
            $table->string('package_state', 16)->default(ServiceLink::PACKAGE_NONE);
            $table->text('held_warnings')->nullable();
            $table->unsignedInteger('approved_at')->nullable();
            $table->text('domain_json')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['server_id', 'hostname']);
        });
    }

    private static function createTokenTableOfVersionOne(): void
    {
        Capsule::schema()->drop(Schema::TOKENS);
        Capsule::schema()->create(Schema::TOKENS, static function (Blueprint $table): void {
            $table->string('fingerprint', 64)->primary();
            $table->text('access_token')->nullable();
            $table->text('scopes')->nullable();
            $table->unsignedInteger('expires_at')->nullable();
            $table->unsignedInteger('blocked_until')->nullable();
            $table->string('block_reason', 255)->nullable();
            $table->unsignedInteger('updated_at');
        });
    }
}
