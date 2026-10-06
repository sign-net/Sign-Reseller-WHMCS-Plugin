<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Hooks;

use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;

/**
 * Warns when a WHMCS service is deleted (ServiceDelete) while its portal is still live, since
 * nothing would bill or terminate it any more. The link is kept, and the dashboard lists it.
 */
final class OrphanedPortalFlagger
{
    /**
     * @param array<string, mixed> $vars ServiceDelete's parameters: serviceid and userid.
     */
    public function flag(array $vars): void
    {
        $services = new ServiceRepository();
        $serviceId = $vars['serviceid'] ?? null;
        $link = is_numeric($serviceId) ? $services->find((int) $serviceId) : null;
        $live = [ServiceLink::STATE_ACTIVE, ServiceLink::STATE_SUSPENDED];
        if ($link === null || !in_array($link->state, $live, true)) {
            return;
        }
        $warning = sprintf(
            'WHMCS service #%d was deleted, but its Sign.net portal %s (tenant %s) is still %s. Terminate it in '
            . 'Sign.net, or link it to another service.',
            $link->serviceId,
            $link->hostname,
            (string) $link->tenantId,
            $link->state,
        );
        $clientId = $vars['userid'] ?? null;
        logActivity('Sign.net: ' . $warning, is_numeric($clientId) ? (int) $clientId : 0);
        $services->save($link->with(['lastError' => $warning]));
    }
}
