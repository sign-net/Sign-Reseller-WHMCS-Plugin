<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * A missing resource, e.g. user_not_found, package_not_found or not_assigned. The console answers
 * these with HTTP 400.
 */
final class NotFoundException extends ApiException
{
}
