<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Http\Transport;
use SignNet\ResellerApi\Support\Sleeper;

/**
 * Sends a request, retrying only where that cannot repeat a side effect, and turns a final
 * answer that is not a success into an exception.
 *
 * A read (a GET, or a POST the caller marked read-only, as the console's searches are) is retried
 * up to twice after a transport failure or a 502, 503 or 504. Anything else is retried once, and
 * only when the transport proves the request never left. A 429 is never retried.
 *
 * @internal
 */
final class RequestSender
{
    private const RETRY_DELAYS_MILLISECONDS = [500, 1500];
    private const MAX_JITTER_MILLISECONDS = 250;
    private const RETRYABLE_STATUSES = [502, 503, 504];

    public function __construct(
        private readonly Transport $transport,
        private readonly ErrorMapper $errors,
        private readonly Sleeper $sleeper,
    ) {
    }

    /**
     * @throws ApiException
     */
    public function send(Request $request): Response
    {
        $retries = 0;
        while (true) {
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $exception) {
                if (!$this->mayRetryAfterFailure($request, $exception, $retries)) {
                    throw $exception;
                }
                $this->pause($retries++);
                continue;
            }

            if ($response->isSuccessful()) {
                return $response;
            }
            if (!$this->mayRetryAfterStatus($request, $response, $retries)) {
                throw $this->errors->toException($request, $response);
            }
            $this->pause($retries++);
        }
    }

    private function mayRetryAfterFailure(Request $request, TransportException $exception, int $retries): bool
    {
        if ($retries >= $this->maxRetries($request)) {
            return false;
        }

        return self::isRead($request) || $exception->requestSent === false;
    }

    private function mayRetryAfterStatus(Request $request, Response $response, int $retries): bool
    {
        return self::isRead($request)
            && $retries < $this->maxRetries($request)
            && in_array($response->status, self::RETRYABLE_STATUSES, true);
    }

    private function maxRetries(Request $request): int
    {
        return self::isRead($request) ? count(self::RETRY_DELAYS_MILLISECONDS) : 1;
    }

    private static function isRead(Request $request): bool
    {
        return $request->method === 'GET' || $request->isReadOnly;
    }

    private function pause(int $retry): void
    {
        $delay = self::RETRY_DELAYS_MILLISECONDS[$retry] ?? self::RETRY_DELAYS_MILLISECONDS[1];
        $this->sleeper->sleepMilliseconds($delay + random_int(0, self::MAX_JITTER_MILLISECONDS));
    }
}
