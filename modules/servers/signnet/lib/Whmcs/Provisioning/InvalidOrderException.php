<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

/**
 * The order cannot become a portal as it stands. The message says what to change and is meant
 * for the administrator or the customer.
 */
final class InvalidOrderException extends \InvalidArgumentException
{
}
