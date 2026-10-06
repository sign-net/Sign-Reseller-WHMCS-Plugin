<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

/**
 * WHMCS's catalogue tables the addon writes: currencies, product and server groups, custom fields,
 * email templates, configurable options and pricing. Text columns are NOT NULL without a default,
 * as WHMCS declares them, so an insert that leaves one out fails here too.
 */
final class WhmcsCatalogueSchema
{
    public const PRICE_COLUMNS = [
        'msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee',
        'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially',
    ];

    public static function create(): void
    {
        $schema = Capsule::schema();

        $schema->create('tblcurrencies', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
            $table->unsignedTinyInteger('default')->default(0);
        });

        foreach (['tblproductgroups', 'tblservergroups'] as $groups) {
            $schema->create($groups, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
            });
        }

        $schema->create('tblcustomfields', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type');
            $table->unsignedInteger('relid');
            $columns = ['fieldname', 'fieldtype', 'description', 'fieldoptions', 'regexpr', 'adminonly', 'required'];
            foreach ([...$columns, 'showorder', 'showinvoice'] as $column) {
                $table->text($column);
            }
            $table->integer('sortorder');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('tblemailtemplates', static function (Blueprint $table): void {
            $table->increments('id');
            foreach (['type', 'name', 'subject', 'message', 'attachments', 'fromname', 'fromemail'] as $column) {
                $table->text($column);
            }
            $table->unsignedTinyInteger('disabled');
            $table->unsignedTinyInteger('custom');
            foreach (['language', 'copyto', 'blind_copy_to'] as $column) {
                $table->text($column);
            }
            $table->unsignedTinyInteger('plaintext');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::createConfigurableOptionTables();
    }

    public static function insertCurrency(string $code, bool $isDefault = false): int
    {
        return Capsule::table('tblcurrencies')->insertGetId(['code' => $code, 'default' => $isDefault ? 1 : 0]);
    }

    /**
     * @param array<string, string> $moduleSettings configoption1..3 values.
     */
    public static function insertProduct(string $name, string $module = '', array $moduleSettings = []): int
    {
        return Capsule::table('tblproducts')->insertGetId($moduleSettings + [
            'name' => $name,
            'servertype' => $module,
            'configoption1' => '',
            'configoption2' => '',
            'configoption3' => '',
        ]);
    }

    public static function insertCustomField(int $productId, string $fieldName): int
    {
        return Capsule::table('tblcustomfields')->insertGetId([
            'type' => 'product',
            'relid' => $productId,
            'fieldname' => $fieldName,
            'fieldtype' => 'text',
            'description' => '',
            'fieldoptions' => '',
            'regexpr' => '',
            'adminonly' => '',
            'required' => 'on',
            'showorder' => 'on',
            'showinvoice' => '',
            'sortorder' => 0,
        ]);
    }

    public static function insertGroup(string $table, string $name): int
    {
        return Capsule::table($table)->insertGetId(['name' => $name]);
    }

    private static function createConfigurableOptionTables(): void
    {
        $schema = Capsule::schema();
        $schema->create('tblproductconfiggroups', static function (Blueprint $table): void {
            $table->increments('id');
            $table->text('name');
            $table->text('description');
        });
        $schema->create('tblproductconfigoptions', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('gid');
            $table->text('optionname');
            $table->text('optiontype');
            $table->integer('qtyminimum');
            $table->integer('qtymaximum');
            $table->integer('order');
            $table->unsignedTinyInteger('hidden');
        });
        $schema->create('tblproductconfigoptionssub', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('configid');
            $table->text('optionname');
            $table->integer('sortorder');
            $table->unsignedTinyInteger('hidden');
        });
        $schema->create('tblproductconfiglinks', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('gid');
            $table->unsignedInteger('pid');
        });
        $schema->create('tblpricing', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type');
            $table->unsignedInteger('currency');
            $table->unsignedInteger('relid');
            foreach (self::PRICE_COLUMNS as $column) {
                $table->decimal($column, 16, 2);
            }
        });
    }
}
