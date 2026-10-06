<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * No HTTP response was received.
 */
final class TransportException extends ApiException
{
    /**
     * @param bool|null $requestSent False when the request certainly never reached the server
     *     (DNS, connect or TLS failure), so retrying it cannot repeat a side effect; true when it
     *     was at least partly sent; null when that cannot be told.
     */
    public function __construct(
        string $message,
        public readonly ?bool $requestSent,
        ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, null, null, $requestId, null, $previous);
    }
}
