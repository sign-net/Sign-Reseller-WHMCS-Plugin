<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use WHMCS\Database\Capsule;

/**
 * The plugin's tables as version 1 created them, for tests of what an upgrade finds.
 */
final class VersionOneTables
{
    public static function createServiceTable(): void
    {
        Capsule::schema()->drop(Schema::SERVICES);
        Capsule::schema()->create(Schema::SERVICES, static function (Blueprint $table): void {
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

    /**
     * @return array<string, mixed>
     */
    public static function serviceRow(int $serviceId, string $state): array
    {
        return [
            'service_id' => $serviceId,
            'server_id' => 1,
            'tenant_id' => sprintf('%032d', $serviceId),
            'hostname' => 'portal' . $serviceId . '.acme.test',
            'state' => $state,
            'created_at' => 1_900_000_000,
            'updated_at' => 1_900_000_000,
        ];
    }

    public static function createTokenTable(): void
    {
        Capsule::schema()->drop(Schema::TOKENS);
        Capsule::schema()->create(Schema::TOKENS, static function (Blueprint $table): void {
            $table->string('fingerprint', 64)->primary();
            $table->text('access_token')->nullable();
            $table->text('scopes')->nullable();
            $table->unsignedInteger('expires_at')->nullable();
            $table->unsignedInteger('blocked_until')->nullable();
            $table->string('block_reason', 255)->nullable();
            $table->unsignedInteger('updated_at');
        });
    }
}
