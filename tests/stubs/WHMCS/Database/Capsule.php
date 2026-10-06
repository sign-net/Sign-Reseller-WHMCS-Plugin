<?php

declare(strict_types=1);

namespace WHMCS\Database;

use Illuminate\Database\Capsule\Manager;

/**
 * Stands in for WHMCS's own Capsule, which is Laravel's Capsule manager under WHMCS's namespace.
 * Tests point it at an in-memory SQLite database.
 */
class Capsule extends Manager
{
}
