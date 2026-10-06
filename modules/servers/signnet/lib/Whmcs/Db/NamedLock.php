<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use WHMCS\Database\Capsule;

/**
 * A lock shared by every PHP process WHMCS runs — web requests, the cron and
 * the module queue — using MySQL's GET_LOCK. On any other database (the SQLite
 * the tests use) it runs the work unlocked.
 */
final class NamedLock
{
    /**
     * Runs `$work` holding the lock, or answers null without running it when the
     * lock is still held by someone else after `$waitSeconds`.
     *
     * @template T
     * @param callable(): T $work
     * @return T|null
     */
    public static function run(string $name, int $waitSeconds, callable $work): mixed
    {
        if (!self::isMysql()) {
            return $work();
        }
        $connection = Capsule::connection();
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [self::key($name), $waitSeconds]);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            return null;
        }
        try {
            return $work();
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [self::key($name)]);
        }
    }

    private static function isMysql(): bool
    {
        return Capsule::connection()->getDriverName() === 'mysql';
    }

    /** MySQL lock names are limited to 64 characters. */
    private static function key(string $name): string
    {
        return 'signnet:' . substr(hash('sha256', $name), 0, 48);
    }
}
