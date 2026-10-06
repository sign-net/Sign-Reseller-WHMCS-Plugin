<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Auth;

/**
 * A store that lives as long as the PHP process: for tests, scripts and long-running workers.
 */
final class InMemoryTokenStore implements TokenStore
{
    /**
     * @var array<string, AccessToken>
     */
    private array $tokens = [];

    /**
     * @var array<string, array{until: int, reason: string}>
     */
    private array $blocks = [];

    public function get(string $fingerprint): ?AccessToken
    {
        return $this->tokens[$fingerprint] ?? null;
    }

    public function put(string $fingerprint, AccessToken $token): void
    {
        $this->tokens[$fingerprint] = $token;
    }

    public function clear(string $fingerprint): void
    {
        unset($this->tokens[$fingerprint]);
    }

    public function blockedUntil(string $fingerprint): ?int
    {
        return $this->blocks[$fingerprint]['until'] ?? null;
    }

    public function blockReason(string $fingerprint): ?string
    {
        return $this->blocks[$fingerprint]['reason'] ?? null;
    }

    public function block(string $fingerprint, int $untilUnix, string $reason): void
    {
        $this->blocks[$fingerprint] = ['until' => $untilUnix, 'reason' => $reason];
    }

    public function withLock(string $fingerprint, callable $callback): mixed
    {
        return $callback();
    }
}
