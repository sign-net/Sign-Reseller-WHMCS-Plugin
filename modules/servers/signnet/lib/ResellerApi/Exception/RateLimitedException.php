<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

use SignNet\ResellerApi\Support\RateLimitInfo;

/**
 * HTTP 429, or token minting paused after the token endpoint answered 429. Never retried
 * automatically.
 */
final class RateLimitedException extends ApiException
{
    /**
     * @param int|null $retryAfterSeconds How long to wait before trying again, when known.
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds,
        ?int $httpStatus = 429,
        ?string $errorCode = null,
        ?string $requestId = null,
        ?RateLimitInfo $rateLimit = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $errorCode, $requestId, $rateLimit, $previous);
    }
}
