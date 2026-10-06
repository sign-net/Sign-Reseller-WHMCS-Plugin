<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

/**
 * The service's portal no longer exists in Sign.net: it was deleted outside WHMCS.
 */
final class PortalGoneException extends \RuntimeException
{
    public function __construct(string $hostname)
    {
        parent::__construct(sprintf(
            'The portal %s no longer exists in Sign.net; it was deleted outside WHMCS. Unlink it, or terminate '
            . 'the service.',
            $hostname,
        ));
    }
}
