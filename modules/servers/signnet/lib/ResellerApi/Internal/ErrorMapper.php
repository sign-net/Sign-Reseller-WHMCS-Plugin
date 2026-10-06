<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\EndpointNotAvailableException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\NotFoundException;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\ValidationException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Support\Clock;
use SignNet\ResellerApi\Support\RateLimitInfo;

/**
 * Turns a response that is not a success into the matching exception.
 *
 * The console answers almost every refusal with 400, so a 400 is told apart by its code: a state
 * that stands in the way is a conflict and something that does not exist is not found, as the
 * retired API's 409 and 404 were; anything else is a validation refusal.
 *
 * @internal
 */
final class ErrorMapper
{
    private const CONFLICT_CODES = [
        ErrorCode::HOST_TAKEN,
        ErrorCode::SUSPENDED_BY_PLATFORM,
        ErrorCode::CODE_IN_USE,
        ErrorCode::CATALOGUE_FULL,
        ErrorCode::ADDON_IN_USE,
        ErrorCode::PACKAGE_INACTIVE,
        ErrorCode::ADDON_INACTIVE,
        ErrorCode::NO_SUBSCRIPTION,
        ErrorCode::ALREADY_ASSIGNED,
        ErrorCode::ALREADY_ATTACHED,
        ErrorCode::CANNOT_REMOVE_OWNER,
        ErrorCode::SEAT_QUOTA_REACHED,
    ];
    private const NOT_FOUND_CODES = [
        ErrorCode::PACKAGE_NOT_FOUND,
        ErrorCode::ADDON_NOT_FOUND,
        ErrorCode::USER_NOT_FOUND,
        ErrorCode::NOT_ASSIGNED,
    ];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function toException(Request $request, Response $response): ApiException
    {
        $status = $response->status;
        $error = ErrorBody::parse($response->body);
        $rateLimit = RateLimitInfo::fromHeaders($response->headers, $this->clock->now());
        $requestId = $request->header('X-Request-Id');
        $call = $request->method . ' ' . $request->target();

        if ($status === 429) {
            return $this->rateLimited($call, $error, $requestId, $rateLimit);
        }
        if ($status === 404 && $error->isRouteNotFound()) {
            return new EndpointNotAvailableException(
                sprintf(
                    'Sign.net API endpoint %s is not available on this server (HTTP 404); '
                    . 'the backend may be older than this client.',
                    $call,
                ),
                $status,
                $error->code,
                $requestId,
                $rateLimit,
            );
        }
        if (!$error->isRecognized) {
            return new ServerException(
                sprintf('Sign.net API call %s failed with HTTP %d and an unrecognised response body.', $call, $status),
                $status,
                null,
                $requestId,
                $rateLimit,
            );
        }

        $message = sprintf(
            'Sign.net API call %s failed with HTTP %d%s.',
            $call,
            $status,
            $error->code === null ? '' : ' (' . $error->code . ')',
        );

        return match ($status) {
            400 => self::refusal($message, $error->code, $requestId, $rateLimit),
            401 => new AuthenticationException($message, $status, $error->code, $requestId, $rateLimit),
            403 => new ForbiddenException($message, $status, $error->code, $requestId, $rateLimit),
            404 => new NotFoundException($message, $status, $error->code, $requestId, $rateLimit),
            409 => new ConflictException($message, $status, $error->code, $requestId, $rateLimit),
            default => new ServerException($message, $status, $error->code, $requestId, $rateLimit),
        };
    }

    private static function refusal(
        string $message,
        ?string $code,
        ?string $requestId,
        ?RateLimitInfo $rateLimit,
    ): ApiException {
        if (in_array($code, self::CONFLICT_CODES, true)) {
            return new ConflictException($message, 400, $code, $requestId, $rateLimit);
        }
        if (in_array($code, self::NOT_FOUND_CODES, true)) {
            return new NotFoundException($message, 400, $code, $requestId, $rateLimit);
        }

        return new ValidationException($message, 400, $code, $requestId, $rateLimit);
    }

    private function rateLimited(
        string $call,
        ErrorBody $error,
        ?string $requestId,
        ?RateLimitInfo $rateLimit,
    ): RateLimitedException {
        $retryAfter = $rateLimit?->waitSeconds();

        return new RateLimitedException(
            sprintf(
                'Sign.net API call %s was rate limited (HTTP 429)%s.',
                $call,
                $retryAfter === null ? '' : sprintf('; retry after %d seconds', $retryAfter),
            ),
            $retryAfter,
            429,
            $error->code,
            $requestId,
            $rateLimit,
        );
    }
}
