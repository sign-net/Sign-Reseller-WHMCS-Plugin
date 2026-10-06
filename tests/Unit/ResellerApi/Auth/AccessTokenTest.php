<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\Scope;

final class AccessTokenTest extends TestCase
{
    private const NOW = 1_760_000_000;

    #[Test]
    public function itReportsTheScopesItCarries(): void
    {
        $token = new AccessToken('jwt', [Scope::READ, Scope::PROVISION], self::NOW + 3600);

        self::assertTrue($token->hasScope(Scope::READ));
        self::assertFalse($token->hasScope(Scope::PACKAGES));
    }

    #[Test]
    public function itStaysUsableOnlyWhileMoreThanTheMarginRemains(): void
    {
        $token = new AccessToken('jwt', [], self::NOW + 301);

        self::assertTrue($token->isUsable(self::NOW));
        self::assertFalse($token->isUsable(self::NOW + 1));
        self::assertTrue($token->isUsable(self::NOW + 1, 0));
        self::assertFalse($token->isUsable(self::NOW + 301, 0));
    }

    #[Test]
    public function itKeepsTheSlugApartFromTheTokenItWasLearntWith(): void
    {
        $token = new AccessToken('jwt', [Scope::READ], self::NOW + 3600);

        $withSlug = $token->withSlug('reseller.sign.test');

        self::assertNull($token->slug);
        self::assertSame(
            ['jwt', [Scope::READ], self::NOW + 3600, 'reseller.sign.test'],
            [$withSlug->token, $withSlug->scopes, $withSlug->expiresAt, $withSlug->slug],
        );
    }

    #[Test]
    public function itMasksTheTokenWhenDumped(): void
    {
        $token = new AccessToken('secret.jwt.value', [Scope::READ], self::NOW);

        self::assertStringNotContainsString('secret.jwt.value', print_r($token, true));
    }
}
