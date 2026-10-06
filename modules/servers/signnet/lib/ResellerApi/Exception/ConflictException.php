<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * The request clashes with the current state, e.g. host_taken, already_assigned,
 * suspended_by_platform or cannot_remove_owner. The console answers these with HTTP 400.
 */
final class ConflictException extends ApiException
{
}
