<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Db;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Whmcs\Db\DbTokenStore;
use SignNet\Whmcs\Db\Schema;
use WHMCS\Database\Capsule;

final class DbTokenStoreTest extends DatabaseTestCase
{
    private const FINGERPRINT = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';

    #[Test]
    public function itKeepsATokenBetweenStoresAndNeverStoresItInPlainText(): void
    {
        (new DbTokenStore())->put(self::FINGERPRINT, new AccessToken('tok-secret', ['reseller:read'], 2_000_000_000));

        $token = (new DbTokenStore())->get(self::FINGERPRINT);

        self::assertNotNull($token);
        self::assertSame('tok-secret', $token->token);
        self::assertSame(['reseller:read'], $token->scopes);
        self::assertSame(2_000_000_000, $token->expiresAt);
        $stored = Capsule::table(Schema::TOKENS)->where('fingerprint', self::FINGERPRINT)->value('access_token');
        self::assertStringNotContainsString('tok-secret', (string) $stored);
    }

    #[Test]
    public function itKeepsTheSlugWithTheToken(): void
    {
        $store = new DbTokenStore();

        $store->put(self::FINGERPRINT, new AccessToken('tok', [], 2_000_000_000, 'reseller.sign.test'));
        $learnt = (new DbTokenStore())->get(self::FINGERPRINT);
        $store->put(self::FINGERPRINT, new AccessToken('tok-2', [], 2_000_000_000));
        $replaced = (new DbTokenStore())->get(self::FINGERPRINT);

        self::assertSame(['reseller.sign.test', null], [$learnt?->slug, $replaced?->slug]);
    }

    #[Test]
    public function itForgetsAClearedToken(): void
    {
        $store = new DbTokenStore();
        $store->put(self::FINGERPRINT, new AccessToken('tok-secret', [], 2_000_000_000, 'reseller.sign.test'));

        $store->clear(self::FINGERPRINT);

        self::assertNull($store->get(self::FINGERPRINT));
        self::assertNull(Capsule::table(Schema::TOKENS)->where('fingerprint', self::FINGERPRINT)->value('slug'));
    }

    #[Test]
    public function itRemembersABlockUntilATokenIsStored(): void
    {
        $store = new DbTokenStore();
        $store->block(self::FINGERPRINT, 1_900_000_000, 'invalid_client');

        self::assertSame(1_900_000_000, $store->blockedUntil(self::FINGERPRINT));
        self::assertSame('invalid_client', $store->blockReason(self::FINGERPRINT));

        $store->put(self::FINGERPRINT, new AccessToken('tok', [], 2_000_000_000));

        self::assertNull($store->blockedUntil(self::FINGERPRINT));
        self::assertNull($store->blockReason(self::FINGERPRINT));
    }

    #[Test]
    public function itRunsWorkUnderItsLockAndReturnsTheResult(): void
    {
        self::assertSame('done', (new DbTokenStore())->withLock(self::FINGERPRINT, static fn (): string => 'done'));
    }
}
