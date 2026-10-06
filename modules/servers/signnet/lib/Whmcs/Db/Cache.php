<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use WHMCS\Database\Capsule;

/** Short-lived answers from Sign.net, so an admin page or a retry does not call it again. */
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
}
