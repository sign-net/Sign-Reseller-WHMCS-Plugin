<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Db;

use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Whmcs\Db\DbTokenStore;
use SignNet\Whmcs\Db\Schema;
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
