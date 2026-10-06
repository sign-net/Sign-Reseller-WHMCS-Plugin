<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\TokenStore;
use WHMCS\Database\Capsule;

/**
 * Access tokens shared by every WHMCS process, encrypted with WHMCS's own key.
 *
 * Sign.net lets one address mint only five tokens per fifteen minutes, and a
 * token lasts an hour, so minting per request would lock the reseller out
 * within minutes. One row per API key and Sign.net address (keyed by
 * ClientConfig::fingerprint(), so a changed key or address never reuses the
 * old one's token).
 */
final class DbTokenStore implements TokenStore
{
    public function get(string $fingerprint): ?AccessToken
    {
        $row = $this->row($fingerprint);
        if ($row === null || $row['access_token'] === null || $row['expires_at'] === null) {
            return null;
        }
        $token = decrypt((string) $row['access_token']);
        if ($token === '') {
            return null;
        }
        $scopes = json_decode((string) $row['scopes'], true);

        return new AccessToken(
            $token,
            is_array($scopes) ? array_values(array_map('strval', $scopes)) : [],
            (int) $row['expires_at'],
            is_string($row['slug'] ?? null) ? $row['slug'] : null,
        );
    }

    public function put(string $fingerprint, AccessToken $token): void
    {
        $this->upsert($fingerprint, [
            'access_token' => encrypt($token->token),
            'scopes' => json_encode($token->scopes),
            'expires_at' => $token->expiresAt,
            'slug' => $token->slug,
            'blocked_until' => null,
            'block_reason' => null,
        ]);
    }

    public function clear(string $fingerprint): void
    {
        $this->upsert($fingerprint, ['access_token' => null, 'scopes' => null, 'expires_at' => null, 'slug' => null]);
    }

    public function blockedUntil(string $fingerprint): ?int
    {
        $row = $this->row($fingerprint);

        return $row === null || $row['blocked_until'] === null ? null : (int) $row['blocked_until'];
    }

    public function blockReason(string $fingerprint): ?string
    {
        $row = $this->row($fingerprint);

        return $row === null || $row['block_reason'] === null ? null : (string) $row['block_reason'];
    }

    public function block(string $fingerprint, int $untilUnix, string $reason): void
    {
        $this->upsert($fingerprint, ['blocked_until' => $untilUnix, 'block_reason' => substr($reason, 0, 255)]);
    }

    public function withLock(string $fingerprint, callable $callback): mixed
    {
        $result = NamedLock::run('token:' . $fingerprint, 15, static fn (): array => [$callback()]);
        if ($result === null) {
            throw new \RuntimeException('Another process is refreshing the Sign.net access token; try again shortly.');
        }

        return $result[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $fingerprint): ?array
    {
        return Rows::first(Capsule::table(Schema::TOKENS)->where('fingerprint', $fingerprint));
    }

    /** @param array<string, mixed> $values */
    private function upsert(string $fingerprint, array $values): void
    {
        Capsule::table(Schema::TOKENS)->updateOrInsert(
            ['fingerprint' => $fingerprint],
            $values + ['updated_at' => time()],
        );
    }
}
