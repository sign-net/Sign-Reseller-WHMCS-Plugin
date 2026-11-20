<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use WHMCS\Database\Capsule;

/**
 * The WHMCS side of a service (tblhosting), as far as the plugin reads and writes it.
 */
final class WhmcsServices
{
    /** WHMCS's statuses for a service it has ended and no longer bills. */
    public const ENDED_STATUSES = ['Cancelled', 'Terminated', 'Fraud'];

    /**
     * WHMCS's status for the service (Pending, Active, Suspended, Terminated, ...), or null when
     * the service no longer exists.
     */
    public static function status(int $serviceId): ?string
    {
        $status = Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus');

        return $status === null ? null : (string) $status;
    }

    /**
     * The WHMCS order the service was made by (0 for one made without an order), or null when the
     * service no longer exists. An administrator's edits of a service never change it, moving the
     * service to another client included.
     */
    public static function orderId(int $serviceId): ?int
    {
        $orderId = Capsule::table('tblhosting')->where('id', $serviceId)->value('orderid');

        return $orderId === null ? null : (int) $orderId;
    }

    /**
     * Shows the portal on the service: its tenant id as the username and its hostname as the domain.
     */
    public static function showPortal(ServiceLink $link): void
    {
        Capsule::table('tblhosting')
            ->where('id', $link->serviceId)
            ->update(['username' => (string) $link->tenantId, 'domain' => $link->hostname]);
    }
}
