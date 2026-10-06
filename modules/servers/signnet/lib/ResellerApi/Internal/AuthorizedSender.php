<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\TokenProvider;
use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Support\Clock;
use SignNet\ResellerApi\Support\RateLimitInfo;

/**
 * Sends reseller API calls with a bearer token. A call refused with 401 (an expired, invalid or
 * revoked token) is retried once with a replaced token: the backend refuses before any handler
 * runs, so the retry cannot repeat a side effect.
 *
 * @internal
 */
final class AuthorizedSender
{
    private const UNAUTHORIZED = 401;

    private ?RateLimitInfo $lastRateLimit = null;

    public function __construct(
        private readonly RequestFactory $requests,
        private readonly RequestSender $sender,
        private readonly TokenProvider $tokens,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @throws ApiException
     */
    public function send(
        string $method,
        string $path,
        ?array $payload,
        int $timeoutSeconds,
        bool $isReadOnly = false,
    ): Exchange {
        $request = $this->requests->create($method, $path, $payload, $timeoutSeconds, $isReadOnly);
        $token = $this->tokens->token();
        try {
            return $this->sendWith($request, $token);
        } catch (AuthenticationException $exception) {
            if ($exception->httpStatus !== self::UNAUTHORIZED) {
                throw $exception;
            }
        }

        return $this->sendWith($request, $this->tokens->refreshAfterRejection($token));
    }

    public function lastRateLimit(): ?RateLimitInfo
    {
        return $this->lastRateLimit;
    }

    private function sendWith(Request $request, AccessToken $token): Exchange
    {
        try {
            $response = $this->sender->send($request->withHeader('Authorization', 'Bearer ' . $token->token));
        } catch (ApiException $exception) {
            if (!$exception instanceof TransportException) {
                $this->lastRateLimit = $exception->rateLimit;
            }
            throw $exception;
        }
        $this->lastRateLimit = RateLimitInfo::fromHeaders($response->headers, $this->clock->now());

        return new Exchange($request, $response);
    }
}
