<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * HTTP 404 from the backend's catch-all route: the endpoint does not exist on this server,
 * typically because the backend is older than this client.
 */
final class EndpointNotAvailableException extends ApiException
{
}
