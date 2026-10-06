<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Auth;

/**
 * Where access tokens live between PHP processes, keyed by ClientConfig::fingerprint(): one
 * entry per key and API address.
 *
 * The token endpoint allows only a handful of calls per IP every 15 minutes, so a store shared
 * by every process that uses the same key is what keeps the client from minting per request.
 */
interface TokenStore
{
    public function get(string $fingerprint): ?AccessToken;

    public function put(string $fingerprint, AccessToken $token): void;

    public function clear(string $fingerprint): void;

    /**
     * The Unix time until which minting a token for this key is paused, or null. A time in the
     * past means the pause is over.
     */
    public function blockedUntil(string $fingerprint): ?int;

    /**
     * The reason given to the latest block() call for this key, or null.
     */
    public function blockReason(string $fingerprint): ?string;

    public function block(string $fingerprint, int $untilUnix, string $reason): void;

    /**
     * Runs $callback while holding a lock on this key that excludes every other process using
     * the same store, and returns its result. Release the lock however $callback ends; when the
     * lock cannot be taken in reasonable time, throw rather than run $callback unlocked.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLock(string $fingerprint, callable $callback): mixed;
}
