<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\WhmcsServices;

/**
 * Which suspension of a portal is WHMCS's own, the one suspension WHMCS lifts on its own.
 *
 * The link records when WHMCS's suspension began, as Sign.net gives it. A suspension that began at
 * another time was made outside WHMCS, in the Sign.net console or by Sign.net, even one that took
 * the place of WHMCS's. A link an earlier version of the plugin recorded as suspended does not know
 * when its suspension began: that suspension is WHMCS's when it carries the reason WHMCS gave the
 * service, and its start is recorded then.
 */
final class WhmcsSuspension
{
    /**
     * The link once Sign.net has reported the portal's suspension: $suspension, or none.
     */
    public static function observe(ServiceLink $link, ?PortalSuspension $suspension): ServiceLink
    {
        if ($suspension === null) {
            return $link->withoutSuspension();
        }

        return self::isWhmcss($link, $suspension)
            ? $link->withWhmcsSuspension($suspension->startedAt)
            : $link->withOutsideSuspension();
    }

    /**
     * Whether $suspension is the one WHMCS made, which the link holds.
     */
    private static function isWhmcss(ServiceLink $link, PortalSuspension $suspension): bool
    {
        if ($suspension->isSignNets) {
            return false;
        }
        if ($link->whmcsSuspendedAt !== null) {
            return $suspension->startedAt === $link->whmcsSuspendedAt;
        }

        return $link->holdsUnrecordedWhmcsSuspension()
            && $suspension->reason === SuspensionReason::fromWhmcs(WhmcsServices::suspendReason($link->serviceId));
    }
}
