<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use WHMCS\Database\Capsule;

/**
 * Short-lived answers from Sign.net, so an admin page or a retry does not call it again, and
 * claims on doing something at most once in a while.
 */
final class Cache
{
    /** @return array<mixed>|null */
    public static function get(string $key): ?array
    {
        $row = Rows::first(Capsule::table(Schema::CACHE)->where('cache_key', $key));
        if ($row === null || (int) $row['expires_at'] < time()) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);

        return is_array($payload) ? $payload : null;
    }

    /** @param array<mixed> $payload */
    public static function put(string $key, array $payload, int $ttlSeconds): void
    {
        Capsule::table(Schema::CACHE)->updateOrInsert(
            ['cache_key' => $key],
            ['payload' => (string) json_encode($payload), 'expires_at' => time() + $ttlSeconds],
        );
    }

    public static function forget(string $key): void
    {
        Capsule::table(Schema::CACHE)->where('cache_key', $key)->delete();
    }

    /**
     * Takes $key for $ttlSeconds unless someone holds it. The insert is the check, so of two
     * processes asking at the same moment only one is given it.
     */
    public static function claim(string $key, int $ttlSeconds): bool
    {
        $now = time();
        Capsule::table(Schema::CACHE)->where('cache_key', $key)->where('expires_at', '<', $now)->delete();

        return Capsule::table(Schema::CACHE)->insertOrIgnore([
            'cache_key' => $key,
            'payload' => '[]',
            'expires_at' => $now + $ttlSeconds,
        ]) === 1;
    }
}
