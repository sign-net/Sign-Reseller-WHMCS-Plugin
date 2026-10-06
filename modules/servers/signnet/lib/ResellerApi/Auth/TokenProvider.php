<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Auth;

use SignNet\ResellerApi\ClientConfig;
use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Http\Transport;
use SignNet\ResellerApi\Internal\ErrorMapper;
use SignNet\ResellerApi\Internal\Exchange;
use SignNet\ResellerApi\Internal\InvalidPayloadException;
use SignNet\ResellerApi\Internal\Payload;
use SignNet\ResellerApi\Internal\RequestFactory;
use SignNet\ResellerApi\Internal\RequestSender;
use SignNet\ResellerApi\Support\Clock;
use SignNet\ResellerApi\Support\Sleeper;
use SignNet\ResellerApi\Support\SystemClock;
use SignNet\ResellerApi\Support\SystemSleeper;

/**
 * Hands out access tokens for one API key: the stored token while it has more than five minutes
 * left, otherwise a new one. Minting runs under the store's lock and re-reads the store first,
 * so processes that race for a token mint once between them.
 *
 * The token endpoint allows five calls per 15 minutes per IP, so after invalid_client minting
 * pauses for five minutes, and after a 429 until the Retry-After time. While paused, minting
 * throws without calling the network.
 */
final class TokenProvider
{
    private const TOKEN_PATH = '/api/v1/auth/token';
    private const INVALID_CLIENT_BLOCK_SECONDS = 300;
    private const TOKEN_RATE_LIMIT_WINDOW_SECONDS = 900;
    private const BLOCK_REASON_INVALID_CLIENT = ErrorCode::INVALID_CLIENT;
    private const BLOCK_REASON_RATE_LIMITED = 'rate_limited';
    private const BEARER_TOKEN_PATTERN = '/^[A-Za-z0-9\-._~+\/]+=*$/D';

    private readonly string $fingerprint;
    private readonly Clock $clock;
    private readonly RequestFactory $requests;
    private readonly RequestSender $sender;

    public function __construct(
        private readonly ClientConfig $config,
        Transport $transport,
        private readonly TokenStore $store,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->fingerprint = $config->fingerprint();
        $this->clock = $clock ?? new SystemClock();
        $this->requests = new RequestFactory($config);
        $this->sender = new RequestSender(
            $transport,
            new ErrorMapper($this->clock),
            $sleeper ?? new SystemSleeper(),
        );
    }

    /**
     * @param bool $forceRefresh Mint a new token even if the stored one is still usable.
     *
     * @throws AuthenticationException When the key is refused, or minting is paused because it was.
     * @throws RateLimitedException When the token endpoint is rate limited, or minting is paused because it was.
     * @throws ApiException
     */
    public function token(bool $forceRefresh = false): AccessToken
    {
        if (!$forceRefresh) {
            $stored = $this->usableStoredToken();
            if ($stored !== null) {
                return $stored;
            }
        }

        return $this->store->withLock($this->fingerprint, function () use ($forceRefresh): AccessToken {
            $stored = $forceRefresh ? null : $this->usableStoredToken();

            return $stored ?? $this->mint();
        });
    }

    /**
     * Replaces a token the API refused with 401. A different usable token that another
     * process stored in the meantime is reused; otherwise the stored token is cleared and a new
     * one minted.
     *
     * @throws ApiException
     */
    public function refreshAfterRejection(AccessToken $rejected): AccessToken
    {
        return $this->store->withLock($this->fingerprint, function () use ($rejected): AccessToken {
            $stored = $this->usableStoredToken();
            if ($stored !== null && $stored->token !== $rejected->token) {
                return $stored;
            }
            $this->store->clear($this->fingerprint);

            return $this->mint();
        });
    }

    /**
     * Keeps the reseller's console slug with the stored token, so other processes using the key
     * need not look it up. Without a usable stored token there is nothing to keep it with.
     */
    public function rememberSlug(string $slug): void
    {
        $this->store->withLock($this->fingerprint, function () use ($slug): void {
            $stored = $this->usableStoredToken();
            if ($stored !== null && $stored->slug !== $slug) {
                $this->store->put($this->fingerprint, $stored->withSlug($slug));
            }
        });
    }

    private function usableStoredToken(): ?AccessToken
    {
        $token = $this->store->get($this->fingerprint);

        return $token !== null && $token->isUsable($this->clock->now()) ? $token : null;
    }

    private function mint(): AccessToken
    {
        $this->assertMintingAllowed();
        $request = $this->requests->create(
            'POST',
            self::TOKEN_PATH,
            ['grant_type' => 'client_credentials', 'api_key' => $this->config->apiKey->reveal()],
            $this->config->timeout,
        );

        try {
            $response = $this->sender->send($request);
        } catch (AuthenticationException $exception) {
            if ($exception->errorCode === ErrorCode::INVALID_CLIENT) {
                $this->block(self::INVALID_CLIENT_BLOCK_SECONDS, self::BLOCK_REASON_INVALID_CLIENT);
            }
            throw $exception;
        } catch (RateLimitedException $exception) {
            $this->block(
                $exception->retryAfterSeconds ?? self::TOKEN_RATE_LIMIT_WINDOW_SECONDS,
                self::BLOCK_REASON_RATE_LIMITED,
            );
            throw $exception;
        }

        $token = (new Exchange($request, $response))->decode($this->parseToken(...));
        $this->store->put($this->fingerprint, $token);

        return $token;
    }

    private function assertMintingAllowed(): void
    {
        $blockedUntil = $this->store->blockedUntil($this->fingerprint);
        $now = $this->clock->now();
        if ($blockedUntil === null || $blockedUntil <= $now) {
            return;
        }

        $pausedUntil = gmdate('Y-m-d H:i:s', $blockedUntil) . ' UTC';
        if ($this->store->blockReason($this->fingerprint) === self::BLOCK_REASON_INVALID_CLIENT) {
            throw new AuthenticationException(
                sprintf(
                    'Token minting for this Sign.net API key is paused until %s because the key was refused '
                    . '(invalid_client).',
                    $pausedUntil,
                ),
                null,
                ErrorCode::INVALID_CLIENT,
            );
        }

        throw new RateLimitedException(
            sprintf(
                'Token minting for this Sign.net API key is paused until %s because the token endpoint '
                . 'rate limit was reached.',
                $pausedUntil,
            ),
            $blockedUntil - $now,
            null,
        );
    }

    private function block(int $seconds, string $reason): void
    {
        $this->store->block($this->fingerprint, $this->clock->now() + $seconds, $reason);
    }

    /**
     * @param array<mixed> $data
     */
    private function parseToken(array $data): AccessToken
    {
        $payload = Payload::of($data);
        $accessToken = $payload->string('access_token');
        if (preg_match(self::BEARER_TOKEN_PATTERN, $accessToken) !== 1) {
            throw InvalidPayloadException::forField('access_token', 'a bearer token');
        }
        if (strcasecmp($payload->string('token_type'), 'Bearer') !== 0) {
            throw InvalidPayloadException::forField('token_type', '"Bearer"');
        }
        $expiresIn = $payload->int('expires_in');
        if ($expiresIn < 1) {
            throw InvalidPayloadException::forField('expires_in', 'a positive integer');
        }

        return new AccessToken(
            $accessToken,
            self::splitScopes($payload->stringOrNull('scope') ?? ''),
            $this->clock->now() + $expiresIn,
        );
    }

    /**
     * @return list<string>
     */
    private static function splitScopes(string $scope): array
    {
        $scopes = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);

        return $scopes === false ? [] : $scopes;
    }
}
