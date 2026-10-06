<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

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
                $table->index(['server_id', 'hostname']);
            });
        }

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

        self::addTokenSlug();

        if (!$schema->hasTable(self::CACHE)) {
            $schema->create(self::CACHE, static function (Blueprint $table): void {
                $table->string('cache_key', 191)->primary();
                $table->mediumText('payload');
                $table->unsignedInteger('expires_at');
            });
        }
    }

    /**
     * The reseller's console slug, kept with its token since the move to the console API. Two
     * processes may both find the column missing; the loser's error is harmless once it exists.
     */
    private static function addTokenSlug(): void
    {
        if (self::hasTokenSlug()) {
            return;
        }
        try {
            Capsule::schema()->table(self::TOKENS, static function (Blueprint $table): void {
                $table->string('slug', 253)->nullable();
            });
        } catch (QueryException $exception) {
            if (!self::hasTokenSlug()) {
                throw $exception;
            }
        }
    }

    /**
     * @phpstan-impure Another process may add the column at any moment.
     */
    private static function hasTokenSlug(): bool
    {
        return Capsule::schema()->hasColumn(self::TOKENS, 'slug');
    }
}
