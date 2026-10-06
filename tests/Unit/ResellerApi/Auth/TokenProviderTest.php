<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Auth;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\TokenProvider;
use SignNet\ResellerApi\Auth\TokenStore;
use SignNet\ResellerApi\ClientConfig;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\Tests\Unit\ResellerApi\ApiTestCase;
use SignNet\Tests\Unit\ResellerApi\Fake\RacingTokenStore;

final class TokenProviderTest extends ApiTestCase
{
    #[Test]
    public function itMintsATokenWithTheClientCredentialsGrant(): void
    {
        $this->queueTokenResponse();

        $token = $this->provider()->token();

        $request = $this->transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame(self::BASE_URL . '/api/v1/auth/token', $request->url);
        self::assertSame(
            ['grant_type' => 'client_credentials', 'api_key' => self::API_KEY],
            self::bodyOf($request),
        );
        self::assertNull($request->header('Authorization'));
        self::assertSame(self::MINTED_TOKEN, $token->token);
        self::assertSame(self::ALL_SCOPES, $token->scopes);
        self::assertSame($this->clock->now() + 3600, $token->expiresAt);
    }

    #[Test]
    public function itStoresTheMintedTokenUnderTheConfigsFingerprint(): void
    {
        $this->queueTokenResponse();

        $this->provider()->token();

        self::assertSame(self::MINTED_TOKEN, $this->store->get($this->config->fingerprint())?->token);
    }

    #[Test]
    public function itReusesAStoredTokenWithoutCallingTheApi(): void
    {
        $this->storeUsableToken();

        $token = $this->provider()->token();

        self::assertSame(self::STORED_TOKEN, $token->token);
        self::assertSame([], $this->transport->requests());
    }

    #[Test]
    public function itReusesATokenWithMoreThanFiveMinutesLeft(): void
    {
        $this->store->put($this->fingerprint(), new AccessToken(self::STORED_TOKEN, [], $this->clock->now() + 301));

        self::assertSame(self::STORED_TOKEN, $this->provider()->token()->token);
    }

    #[Test]
    public function itRefreshesATokenWithinFiveMinutesOfExpiry(): void
    {
        $this->store->put($this->fingerprint(), new AccessToken(self::STORED_TOKEN, [], $this->clock->now() + 300));
        $this->queueTokenResponse();

        self::assertSame(self::MINTED_TOKEN, $this->provider()->token()->token);
        self::assertSame(self::MINTED_TOKEN, $this->store->get($this->fingerprint())?->token);
    }

    #[Test]
    public function itUsesTheTokenAnotherProcessMintedWhileItWaitedForTheLock(): void
    {
        $store = new RacingTokenStore(
            new AccessToken('other-process.jwt', self::ALL_SCOPES, $this->clock->now() + 3600),
        );

        $token = $this->provider($store)->token();

        self::assertSame('other-process.jwt', $token->token);
        self::assertSame(1, $store->lockCount());
        self::assertSame([], $this->transport->requests());
    }

    #[Test]
    public function itMintsUnderTheLockWhenNoUsableTokenIsStored(): void
    {
        $store = new RacingTokenStore(new AccessToken('expired.jwt', [], $this->clock->now() + 10));
        $this->queueTokenResponse();

        $token = $this->provider($store)->token();

        self::assertSame(self::MINTED_TOKEN, $token->token);
        self::assertSame(1, $store->lockCount());
    }

    #[Test]
    public function itMintsOnAForcedRefreshEvenWithAUsableToken(): void
    {
        $this->storeUsableToken();
        $this->queueTokenResponse();

        self::assertSame(self::MINTED_TOKEN, $this->provider()->token(forceRefresh: true)->token);
    }

    #[Test]
    public function itPausesMintingForFiveMinutesAfterInvalidClient(): void
    {
        $this->transport->queueJson(401, ['error' => 'invalid_client']);
        $provider = $this->provider();

        $first = $this->catchAuthentication(static fn () => $provider->token());
        self::assertSame(ErrorCode::INVALID_CLIENT, $first->errorCode);
        self::assertSame(401, $first->httpStatus);
        self::assertSame($this->clock->now() + 300, $this->store->blockedUntil($this->fingerprint()));

        $this->clock->advance(299);
        $paused = $this->catchAuthentication(static fn () => $provider->token());
        self::assertSame(ErrorCode::INVALID_CLIENT, $paused->errorCode);
        self::assertNull($paused->httpStatus);
        self::assertCount(1, $this->transport->requests());

        $this->clock->advance(1);
        $this->queueTokenResponse();
        self::assertSame(self::MINTED_TOKEN, $provider->token()->token);
    }

    #[Test]
    public function aKeyRefusedAtOneAddressIsTriedAtAnotherStraightAway(): void
    {
        $this->transport->queueJson(401, ['error' => 'invalid_client']);
        $this->catchAuthentication(fn () => $this->provider()->token());
        $this->queueTokenResponse();
        $elsewhere = new TokenProvider(
            new ClientConfig('https://other.example.test', self::API_KEY),
            $this->transport,
            $this->store,
            $this->clock,
            $this->sleeper,
        );

        self::assertSame(self::MINTED_TOKEN, $elsewhere->token()->token);
        self::assertSame('https://other.example.test/api/v1/auth/token', $this->transport->lastRequest()->url);
    }

    #[Test]
    public function itPausesMintingUntilRetryAfterSecondsWhenRateLimited(): void
    {
        $this->transport->queueJson(
            429,
            ['error' => 'Too many requests, please try again later.'],
            ['Retry-After' => '120', 'RateLimit-Limit' => '5', 'RateLimit-Remaining' => '0'],
        );
        $provider = $this->provider();

        $first = $this->catchRateLimited(static fn () => $provider->token());
        self::assertSame(120, $first->retryAfterSeconds);
        self::assertSame($this->clock->now() + 120, $this->store->blockedUntil($this->fingerprint()));

        $this->clock->advance(20);
        $paused = $this->catchRateLimited(static fn () => $provider->token());
        self::assertSame(100, $paused->retryAfterSeconds);
        self::assertNull($paused->httpStatus);
        self::assertCount(1, $this->transport->requests());
    }

    #[Test]
    public function itPausesMintingUntilARetryAfterHttpDate(): void
    {
        $retryAt = $this->clock->now() + 600;
        $this->transport->queueJson(
            429,
            ['error' => 'Too many requests, please try again later.'],
            ['Retry-After' => gmdate('D, d M Y H:i:s', $retryAt) . ' GMT'],
        );

        $exception = $this->catchRateLimited(fn () => $this->provider()->token());

        self::assertSame(600, $exception->retryAfterSeconds);
        self::assertSame($retryAt, $this->store->blockedUntil($this->fingerprint()));
    }

    #[Test]
    public function itPausesMintingForTheWholeWindowWhenA429GivesNoDelay(): void
    {
        $this->transport->queueJson(429, ['error' => 'Too many requests, please try again later.']);

        $this->catchRateLimited(fn () => $this->provider()->token());

        self::assertSame($this->clock->now() + 900, $this->store->blockedUntil($this->fingerprint()));
    }

    #[Test]
    public function itStillHandsOutAStoredTokenWhileMintingIsPaused(): void
    {
        $this->storeUsableToken();
        $this->store->block($this->fingerprint(), $this->clock->now() + 300, ErrorCode::INVALID_CLIENT);

        self::assertSame(self::STORED_TOKEN, $this->provider()->token()->token);
    }

    #[Test]
    public function itRetriesTheMintOnceWhenTheRequestNeverLeft(): void
    {
        $this->transport->queue(new TransportException('connect failed', false));
        $this->queueTokenResponse();

        self::assertSame(self::MINTED_TOKEN, $this->provider()->token()->token);
        self::assertCount(2, $this->transport->requests());
    }

    #[Test]
    public function itRefusesAMalformedTokenResponseAndStoresNothing(): void
    {
        $this->transport->queueJson(200, ['token_type' => 'Bearer', 'expires_in' => 3600]);

        try {
            $this->provider()->token();
            self::fail('A token response without access_token was accepted.');
        } catch (ServerException $exception) {
            self::assertStringContainsString('access_token', $exception->getMessage());
        }
        self::assertNull($this->store->get($this->fingerprint()));
    }

    #[Test]
    public function itRefusesATokenTypeOtherThanBearer(): void
    {
        $this->transport->queueJson(200, [
            'access_token' => 'abc',
            'token_type' => 'MAC',
            'expires_in' => 3600,
            'scope' => '',
        ]);

        $this->expectException(ServerException::class);

        $this->provider()->token();
    }

    #[Test]
    public function itRefusesATokenThatCouldInjectHeaders(): void
    {
        $this->transport->queueJson(200, [
            'access_token' => "abc\r\nX-Evil: 1",
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);

        $this->expectException(ServerException::class);

        $this->provider()->token();
    }

    #[Test]
    public function itReplacesARejectedTokenAndClearsItFirst(): void
    {
        $this->storeUsableToken();
        $rejected = $this->provider()->token();
        $this->transport->queueJson(401, ['error' => 'invalid_client']);

        try {
            $this->provider()->refreshAfterRejection($rejected);
            self::fail('The refresh should have failed.');
        } catch (AuthenticationException) {
            self::assertNull($this->store->get($this->fingerprint()));
        }
    }

    #[Test]
    public function itRemembersTheSlugWithTheStoredToken(): void
    {
        $this->storeUsableToken(slug: null);

        $this->provider()->rememberSlug('reseller.sign.test');

        $stored = $this->store->get($this->fingerprint());
        self::assertSame([self::STORED_TOKEN, 'reseller.sign.test'], [$stored?->token, $stored?->slug]);
    }

    #[Test]
    public function itHasNoTokenToRememberASlugWithOnceTheTokenIsSpent(): void
    {
        $this->store->put($this->fingerprint(), new AccessToken(self::STORED_TOKEN, [], $this->clock->now() + 60));

        $this->provider()->rememberSlug('reseller.sign.test');

        self::assertNull($this->store->get($this->fingerprint())?->slug);
        self::assertSame([], $this->transport->requests());
    }

    #[Test]
    public function itKeepsTheSecretOutOfEveryMessage(): void
    {
        $this->transport->queueJson(401, ['error' => 'invalid_client']);
        $provider = $this->provider();

        $first = $this->catchAuthentication(static fn () => $provider->token());
        $paused = $this->catchAuthentication(static fn () => $provider->token());

        self::assertNoSecretsIn($first->getMessage());
        self::assertNoSecretsIn($paused->getMessage());
        self::assertNoSecretsIn(print_r($first, true));
    }

    private function provider(?TokenStore $store = null): TokenProvider
    {
        return new TokenProvider($this->config, $this->transport, $store ?? $this->store, $this->clock, $this->sleeper);
    }

    private function catchAuthentication(callable $call): AuthenticationException
    {
        try {
            $call();
        } catch (AuthenticationException $exception) {
            return $exception;
        }
        self::fail('Expected an AuthenticationException.');
    }

    private function catchRateLimited(callable $call): RateLimitedException
    {
        try {
            $call();
        } catch (RateLimitedException $exception) {
            return $exception;
        }
        self::fail('Expected a RateLimitedException.');
    }
}
