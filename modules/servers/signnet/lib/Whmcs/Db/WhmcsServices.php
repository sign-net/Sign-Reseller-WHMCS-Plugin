<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use SignNet\Whmcs\Provisioning\HostnameNormalizer;
use WHMCS\Database\Capsule;

/**
 * The WHMCS side of a service (tblhosting), as far as the plugin reads and writes it.
 */
final class WhmcsServices
{
    /** WHMCS's statuses for a service it has ended and no longer bills. */
    public const ENDED_STATUSES = ['Cancelled', 'Terminated', 'Fraud'];

    /** A tenant id as Sign.net issues it, and as the service's Username holds it. */
    public const TENANT_ID_PATTERN = '/^[0-9a-f]{32}$/D';

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
     * The reason WHMCS recorded for suspending the service, empty when it has none.
     */
    public static function suspendReason(int $serviceId): string
    {
        return (string) Capsule::table('tblhosting')->where('id', $serviceId)->value('suspendreason');
    }

    /**
     * The WHMCS order the service was made by (0 for one made without an order), or null when the
     * service no longer exists. An administrator's edits of a service never change it, moving the
     * service to another client included.
     */
    public static function orderId(int $serviceId): ?int
    {
        $service = Rows::first(Capsule::table('tblhosting')->where('id', $serviceId)->select('orderid'));

        return $service === null ? null : (int) ($service['orderid'] ?? 0);
    }

    /**
     * Shows the portal on the service: its tenant id as the username and its hostname as the domain.
     * WHMCS prints both as stored, so they are written only in the form Sign.net gives them, which
     * holds no character HTML treats specially.
     *
     * @throws \UnexpectedValueException When either is not in that form; the service is left as it was.
     */
    public static function showPortal(ServiceLink $link): void
    {
        if (
            preg_match(self::TENANT_ID_PATTERN, (string) $link->tenantId) !== 1
            || !HostnameNormalizer::isValid($link->hostname)
        ) {
            throw new \UnexpectedValueException(
                'Sign.net gave this portal a tenant id or address that is not valid, so the service\'s Username '
                . 'and Domain were left as they were. Contact Sign.net.',
            );
        }
        Capsule::table('tblhosting')
            ->where('id', $link->serviceId)
            ->update(['username' => (string) $link->tenantId, 'domain' => $link->hostname]);
    }
}
