<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * HTTP 400: the backend refused the request as malformed or not applicable to its target, with
 * a code that is neither a conflict nor a missing resource.
 */
final class ValidationException extends ApiException
{
}
