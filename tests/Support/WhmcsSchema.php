<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * The slice of WHMCS's own tables the plugin reads and writes, with only the columns it uses.
 */
final class WhmcsSchema
{
    public static function create(): void
    {
        $schema = Capsule::schema();

        $schema->create('tblhosting', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('userid')->default(0);
            $table->unsignedInteger('packageid')->default(0);
            $table->unsignedInteger('server')->default(0);
            $table->string('domain')->default('');
            $table->string('username')->default('');
            $table->string('domainstatus')->default('Pending');
        });

        $schema->create('tblservers', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('');
            $table->string('type')->default('');
            $table->string('hostname')->default('');
            $table->string('secure')->default('');
            $table->string('port')->default('');
            $table->text('password');
            $table->unsignedTinyInteger('disabled')->default(0);
        });

        $schema->create('tbladdonmodules', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value');
        });

        $schema->create('tblcancelrequests', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('relid');
            $table->string('type')->default('End of Billing Period');
            $table->text('reason');
        });

        $schema->create('tblproducts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('gid')->default(0);
            $table->string('name')->default('');
            $table->string('servertype')->default('');
            $table->text('configoption1');
            $table->text('configoption2');
            $table->text('configoption3');
        });
    }

    /**
     * A Sign.net server record as WHMCS stores it, the key encrypted.
     */
    public static function insertServer(string $apiKey, string $hostname = 'api.sign.test'): int
    {
        return Capsule::table('tblservers')->insertGetId([
            'name' => 'Sign.net',
            'type' => 'signnet',
            'hostname' => $hostname,
            'secure' => 'on',
            'port' => '',
            'password' => encrypt($apiKey),
        ]);
    }

    /**
     * @param array<string, string> $settings
     */
    public static function setAddonSettings(array $settings): void
    {
        foreach ($settings as $setting => $value) {
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => 'signnet_reseller', 'setting' => $setting],
                ['value' => $value],
            );
        }
    }
}
