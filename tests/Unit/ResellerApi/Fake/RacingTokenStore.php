<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Fake;

use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\InMemoryTokenStore;
use SignNet\ResellerApi\Auth\TokenStore;

/**
 * Simulates another process that stores a token while this one waits for the lock.
 */
final class RacingTokenStore implements TokenStore
{
    private readonly InMemoryTokenStore $inner;
    private int $lockCount = 0;

    public function __construct(private readonly AccessToken $tokenFromOtherProcess)
    {
        $this->inner = new InMemoryTokenStore();
    }

    public function get(string $fingerprint): ?AccessToken
    {
        return $this->inner->get($fingerprint);
    }

    public function put(string $fingerprint, AccessToken $token): void
    {
        $this->inner->put($fingerprint, $token);
    }

    public function clear(string $fingerprint): void
    {
        $this->inner->clear($fingerprint);
    }

    public function blockedUntil(string $fingerprint): ?int
    {
        return $this->inner->blockedUntil($fingerprint);
    }

    public function blockReason(string $fingerprint): ?string
    {
        return $this->inner->blockReason($fingerprint);
    }

    public function block(string $fingerprint, int $untilUnix, string $reason): void
    {
        $this->inner->block($fingerprint, $untilUnix, $reason);
    }

    public function withLock(string $fingerprint, callable $callback): mixed
    {
        $this->lockCount++;
        $this->inner->put($fingerprint, $this->tokenFromOtherProcess);

        return $callback();
    }

    public function lockCount(): int
    {
        return $this->lockCount;
    }
}
