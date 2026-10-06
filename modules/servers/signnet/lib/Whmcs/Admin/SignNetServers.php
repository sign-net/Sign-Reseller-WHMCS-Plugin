<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Support\Settings;
use SignNet\Whmcs\Version;
use WHMCS\Database\Capsule;

/**
 * The WHMCS server records of type Sign.net that are not disabled; each is one reseller account.
 */
final class SignNetServers
{
    /**
     * @return array<int, string> Server id => name, oldest first.
     */
    public static function active(): array
    {
        $servers = [];
        $query = Capsule::table('tblservers')
            ->select('id', 'name')
            ->where('type', Version::MODULE)
            ->where('disabled', 0)
            ->orderBy('id');
        foreach (Rows::all($query) as $row) {
            $servers[(int) $row['id']] = (string) $row['name'];
        }

        return $servers;
    }

    /**
     * The server the addon's pages use: the default server setting while it is still an active
     * Sign.net server, else the oldest one; null when there is none.
     */
    public static function forPages(Settings $settings): ?int
    {
        $servers = self::active();
        $preferred = $settings->defaultServerId();

        return $preferred !== null && isset($servers[$preferred]) ? $preferred : array_key_first($servers);
    }
}
