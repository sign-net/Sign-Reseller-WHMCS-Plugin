<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

use PHPUnit\Framework\TestCase;
use SignNet\Whmcs\Db\Schema;
use WHMCS\Database\Capsule;

/**
 * A fresh in-memory WHMCS database for every test: WHMCS's own tables the plugin touches, plus
 * the plugin's.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WhmcsFake::reset();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        WhmcsSchema::create();
        Schema::install();
    }
}
