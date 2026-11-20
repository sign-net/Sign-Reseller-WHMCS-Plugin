<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * The plugin's own tables. Created by the addon's activate/upgrade and, so a
 * module call never runs before them, checked once per request by the server
 * module too. Every step is idempotent; nothing here ever drops a table.
 */
final class Schema
{
    public const SERVICES = 'mod_signnet_services';
    public const TOKENS = 'mod_signnet_tokens';
    public const CACHE = 'mod_signnet_cache';

    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::install();
        self::$ensured = true;
    }

    public static function install(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::SERVICES)) {
            $schema->create(self::SERVICES, static function (Blueprint $table): void {
                $table->unsignedInteger('service_id')->primary();
                $table->unsignedInteger('server_id');
                $table->string('tenant_id', 32)->nullable()->unique();
                $table->string('hostname', 253)->default('');
                $table->string('portal_name', 255)->default('');
                $table->string('owner_email', 255)->default('');
                $table->string('state', 16)->default(ServiceLink::STATE_PENDING);
                $table->string('attempt_state', 16)->default(ServiceLink::ATTEMPT_NONE);
                $table->unsignedInteger('attempt_at')->nullable();
                $table->string('package_state', 16)->default(ServiceLink::PACKAGE_NONE);
                $table->text('held_warnings')->nullable();
                $table->unsignedInteger('approved_at')->nullable();
                $table->text('domain_json')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unsignedInteger('suspended_outside_at')->nullable();
                $table->integer('order_id')->nullable();
                $table->index(['server_id', 'hostname']);
            });
        }

        self::addSuspendedOutsideAt();
        // The WHMCS order of the service each link was made for.
        self::addColumn(self::SERVICES, 'order_id', static function (Blueprint $table): void {
            $table->integer('order_id')->nullable();
        });
        self::recordServiceOrders();

        if (!$schema->hasTable(self::TOKENS)) {
            $schema->create(self::TOKENS, static function (Blueprint $table): void {
                $table->string('fingerprint', 64)->primary();
                $table->text('access_token')->nullable();
                $table->text('scopes')->nullable();
                $table->unsignedInteger('expires_at')->nullable();
                $table->string('slug', 253)->nullable();
                $table->unsignedInteger('blocked_until')->nullable();
                $table->string('block_reason', 255)->nullable();
                $table->unsignedInteger('updated_at');
            });
        }

        // The reseller's console slug, kept with its token since the move to the console API.
        self::addColumn(self::TOKENS, 'slug', static function (Blueprint $table): void {
            $table->string('slug', 253)->nullable();
        });

        if (!$schema->hasTable(self::CACHE)) {
            $schema->create(self::CACHE, static function (Blueprint $table): void {
                $table->string('cache_key', 191)->primary();
                $table->mediumText('payload');
                $table->unsignedInteger('expires_at');
            });
        }
    }

    /**
     * When the plugin learnt that a service's portal holds a suspension WHMCS did not make. A link
     * saved before then as suspended, while WHMCS does not show its service suspended, holds such a
     * suspension: WHMCS's own would have suspended the service too.
     */
    private static function addSuspendedOutsideAt(): void
    {
        if (self::hasColumn(self::SERVICES, 'suspended_outside_at')) {
            return;
        }
        self::addColumn(self::SERVICES, 'suspended_outside_at', static function (Blueprint $table): void {
            $table->unsignedInteger('suspended_outside_at')->nullable();
        });
        Capsule::table(self::SERVICES)
            ->where('state', ServiceLink::STATE_SUSPENDED)
            ->whereNull('suspended_outside_at')
            ->whereNotIn('service_id', static function (Builder $query): void {
                $query->select('id')->from('tblhosting')->where('domainstatus', 'Suspended');
            })
            ->update(['suspended_outside_at' => time()]);
    }

    /**
     * Records the WHMCS order of the service of each link that has none yet: a link an earlier version
     * saved. A link whose service is gone is given an order no service has, so that a newer service
     * WHMCS gives the same id never matches it.
     */
    private static function recordServiceOrders(): void
    {
        $unrecorded = static fn (): Builder => Capsule::table(self::SERVICES)->whereNull('order_id');
        if (!$unrecorded()->exists()) {
            return;
        }
        $orderOfService = sprintf(
            'COALESCE((SELECT tblhosting.orderid FROM tblhosting WHERE tblhosting.id = %s.service_id), %d)',
            self::SERVICES,
            ServiceLink::ORDER_OF_DELETED_SERVICE,
        );
        $unrecorded()->update(['order_id' => Capsule::connection()->raw($orderOfService)]);
    }

    /**
     * Adds a column to a table an earlier version created. Two processes may both find it missing;
     * the loser's error is harmless once the column exists.
     *
     * @param \Closure(Blueprint): void $define
     */
    private static function addColumn(string $table, string $column, \Closure $define): void
    {
        if (self::hasColumn($table, $column)) {
            return;
        }
        try {
            Capsule::schema()->table($table, $define);
        } catch (QueryException $exception) {
            if (!self::hasColumn($table, $column)) {
                throw $exception;
            }
        }
    }

    /**
     * @phpstan-impure Another process may add the column at any moment.
     */
    private static function hasColumn(string $table, string $column): bool
    {
        return Capsule::schema()->hasColumn($table, $column);
    }
}
