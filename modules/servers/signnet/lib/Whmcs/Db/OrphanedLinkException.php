<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

/**
 * The link saved under a service's id was made for a deleted service that had the same id, and
 * still manages that service's live portal.
 */
final class OrphanedLinkException extends \RuntimeException
{
    public function __construct(ServiceLink $link)
    {
        parent::__construct(sprintf(
            'The Sign.net portal %1$s (tenant %2$s) was made for a WHMCS service since deleted, and WHMCS has given '
            . 'that service\'s id, #%3$d, to this newer service. Nothing was done to the portal. To give this '
            . 'service a portal of its own, click "Unlink (keeps the portal)", then run Create. To manage %1$s '
            . 'again, unlink it here, then use "Link existing portal" on the service it belongs to.',
            $link->hostname,
            (string) $link->tenantId,
            $link->serviceId,
        ));
    }
}
