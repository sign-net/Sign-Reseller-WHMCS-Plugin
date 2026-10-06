<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * HTTP 5xx, an unexpected status, or a response body this client cannot interpret.
 */
final class ServerException extends ApiException
{
}
