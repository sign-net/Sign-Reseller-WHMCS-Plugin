<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

use SignNet\ResellerApi\Support\RateLimitInfo;

/**
 * Base class of every failure the client reports. Messages never contain the API key or an
 * access token, so they are safe to log and to show to an administrator.
 */
abstract class ApiException extends \RuntimeException
{
    /**
     * @param int|null $httpStatus The HTTP status of the failed call; null when none was received.
     * @param string|null $errorCode The backend's error code in lower snake case, e.g. "invalid_target".
     * @param string|null $requestId The X-Request-Id this client sent with the failed call.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly ?RateLimitInfo $rateLimit = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
