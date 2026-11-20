<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

/**
 * Reading the portal from Sign.net failed a moment ago, and it is not asked again until that
 * failure has had time to clear. The message is how the failed read was described then.
 */
final class PortalUnavailableException extends \RuntimeException
{
}
