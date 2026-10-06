<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\EndpointNotAvailableException;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\NotFoundException;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\ValidationException;
use SignNet\ResellerApi\Http\Response;

final class ErrorMappingTest extends ClientTestCase
{
    /**
     * @return iterable<string, array{int, string, class-string<ApiException>, string|null}>
     */
    public static function errorResponses(): iterable
    {
        $json = static fn (array $body): string => json_encode($body, JSON_THROW_ON_ERROR);
        $envelope = static fn (string $status, string $code, string $message): string => $json([
            'status' => $status,
            'error' => ['code' => $code, 'message' => $message],
        ]);
        $refusal = static fn (string $code): string => $envelope('Err', $code, 'Refused.');

        yield '400 INVALID_REQUEST' => [
            400,
            $refusal('INVALID_REQUEST'),
            ValidationException::class,
            'invalid_request',
        ];
        yield '400 INVALID_TARGET' => [400, $refusal('INVALID_TARGET'), ValidationException::class, 'invalid_target'];
        yield '400 CONFIRMATION_EXPIRED' => [
            400,
            $refusal('CONFIRMATION_EXPIRED'),
            ValidationException::class,
            'confirmation_expired',
        ];
        yield '400 INVALID_JSON' => [400, $refusal('INVALID_JSON'), ValidationException::class, 'invalid_json'];
        yield '400 OWNER_INVALID_EMAIL' => [
            400,
            $refusal('OWNER_INVALID_EMAIL'),
            ValidationException::class,
            'owner_invalid_email',
        ];
        yield '400 HOST_TAKEN' => [400, $refusal('HOST_TAKEN'), ConflictException::class, 'host_taken'];
        yield '400 SUSPENDED_BY_PLATFORM' => [
            400,
            $refusal('SUSPENDED_BY_PLATFORM'),
            ConflictException::class,
            'suspended_by_platform',
        ];
        yield '400 CANNOT_REMOVE_OWNER' => [
            400,
            $refusal('CANNOT_REMOVE_OWNER'),
            ConflictException::class,
            'cannot_remove_owner',
        ];
        yield '400 SEAT_QUOTA_REACHED' => [
            400,
            $refusal('SEAT_QUOTA_REACHED'),
            ConflictException::class,
            'seat_quota_reached',
        ];
        yield '400 USER_NOT_FOUND' => [400, $refusal('USER_NOT_FOUND'), NotFoundException::class, 'user_not_found'];
        yield '400 NOT_ASSIGNED' => [400, $refusal('NOT_ASSIGNED'), NotFoundException::class, 'not_assigned'];
        yield '400 in the token endpoint\'s shape' => [
            400,
            $json(['error' => 'invalid_request']),
            ValidationException::class,
            'invalid_request',
        ];
        yield '403 INSUFFICIENT_SCOPE' => [
            403,
            $refusal('INSUFFICIENT_SCOPE'),
            ForbiddenException::class,
            'insufficient_scope',
        ];
        yield '403 SESSION_REQUIRED' => [
            403,
            $refusal('SESSION_REQUIRED'),
            ForbiddenException::class,
            'session_required',
        ];
        yield '404 unknown route' => [
            404,
            $envelope('Err', 'NOT_FOUND', 'Invalid API Endpoint.'),
            EndpointNotAvailableException::class,
            'not_found',
        ];
        yield '404 without a body' => [404, '', ServerException::class, null];
        yield '429 limiter sentence' => [
            429,
            $json(['error' => 'Too many requests, please try again later.']),
            RateLimitedException::class,
            null,
        ];
        yield '429 TOO_FREQUENT' => [429, $refusal('TOO_FREQUENT'), RateLimitedException::class, 'too_frequent'];
        yield '429 non-JSON body' => [429, 'Too Many Requests', RateLimitedException::class, null];
        yield '500 Fail envelope' => [
            500,
            $envelope('Fail', 'INTERNAL_SERVER_ERROR', 'An internal server error occurred.'),
            ServerException::class,
            'internal_server_error',
        ];
        yield '502 HTML page' => [502, '<html><body>Bad Gateway</body></html>', ServerException::class, null];
        yield '401 non-JSON body' => [401, 'Unauthorized', ServerException::class, null];
        yield '403 unknown JSON shape' => [403, $json(['message' => 'Forbidden']), ServerException::class, null];
        yield '418 unexpected status' => [418, $json(['error' => 'teapot']), ServerException::class, 'teapot'];
        yield '302 redirect' => [302, '', ServerException::class, null];
    }

    /**
     * @param class-string<ApiException> $expectedClass
     */
    #[Test]
    #[DataProvider('errorResponses')]
    public function itMapsEachErrorResponseToItsException(
        int $status,
        string $body,
        string $expectedClass,
        ?string $expectedCode,
    ): void {
        $this->storeUsableToken();
        $this->transport->queue(new Response($status, ['Content-Type' => 'application/json'], $body));

        $exception = $this->failingCall();

        self::assertInstanceOf($expectedClass, $exception);
        self::assertSame($status, $exception->httpStatus);
        self::assertSame($expectedCode, $exception->errorCode);
        self::assertSame($this->transport->lastRequest()->header('X-Request-Id'), $exception->requestId);
        self::assertStringContainsString(
            'POST ' . self::API_PATH . '/private-labels/tenant-1/users/user-1/invite',
            $exception->getMessage(),
        );
        self::assertNoSecretsIn($exception->getMessage());
    }

    #[Test]
    public function itCarriesTheRateLimitHeadersOfA429(): void
    {
        $this->storeUsableToken();
        $this->transport->queueJson(
            429,
            ['error' => 'Too many requests, please try again later.'],
            ['Retry-After' => '42', 'RateLimit-Limit' => '20', 'RateLimit-Remaining' => '0', 'RateLimit-Reset' => '42'],
        );

        $exception = $this->failingCall();

        self::assertInstanceOf(RateLimitedException::class, $exception);
        self::assertSame(42, $exception->retryAfterSeconds);
        $rateLimit = $exception->rateLimit;
        self::assertNotNull($rateLimit);
        self::assertSame(20, $rateLimit->limit);
        self::assertSame(0, $rateLimit->remaining);
        self::assertStringContainsString('retry after 42 seconds', $exception->getMessage());
    }

    #[Test]
    public function itFallsBackToTheWindowResetWhenA429HasNoRetryAfter(): void
    {
        $this->storeUsableToken();
        $this->transport->queueJson(
            429,
            ['error' => 'Too many requests, please try again later.'],
            ['RateLimit-Reset' => '17'],
        );

        $exception = $this->failingCall();

        self::assertInstanceOf(RateLimitedException::class, $exception);
        self::assertSame(17, $exception->retryAfterSeconds);
    }

    #[Test]
    public function itUsesTheHttpStatusAsTheExceptionCode(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(400, 'already_assigned');

        self::assertSame(400, $this->failingCall()->getCode());
    }

    #[Test]
    public function aTokenRefusedTwiceIsAnAuthenticationFailure(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(401, 'unauthorized');
        $this->queueTokenResponse();
        $this->transport->queueError(401, 'unauthorized');

        $exception = $this->failingCall();

        self::assertInstanceOf(AuthenticationException::class, $exception);
        self::assertSame(401, $exception->httpStatus);
        self::assertSame('unauthorized', $exception->errorCode);
    }

    #[Test]
    public function aForbiddenTargetIsAForbiddenFailureOnceTheSlugChecksOut(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(403, 'forbidden');
        $this->queueDashboard();

        $exception = $this->failingCall();

        self::assertInstanceOf(ForbiddenException::class, $exception);
        self::assertSame('forbidden', $exception->errorCode);
    }

    private function failingCall(): ApiException
    {
        try {
            $this->client->resendInvite('tenant-1', 'user-1');
        } catch (ApiException $exception) {
            return $exception;
        }
        self::fail('The call was expected to fail.');
    }
}
