<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Tests\Unit\ResellerApi\Fake\RacingTokenStore;

final class InvalidTokenRetryTest extends ClientTestCase
{
    #[Test]
    public function itMintsOnceAndRetriesOnceWhenTheTokenIsRefused(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(401, 'unauthorized');
        $this->queueTokenResponse();
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $this->client->getQuota();

        $requests = $this->transport->requests();
        self::assertCount(3, $requests);
        self::assertSame('Bearer ' . self::STORED_TOKEN, $requests[0]->header('Authorization'));
        self::assertStringEndsWith('/api/v1/auth/token', $requests[1]->url);
        self::assertSame('Bearer ' . self::MINTED_TOKEN, $requests[2]->header('Authorization'));
        self::assertSame(self::MINTED_TOKEN, $this->store->get($this->fingerprint())?->token);
    }

    #[Test]
    public function itGivesUpWhenTheFreshTokenIsRefusedToo(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(401, 'unauthorized');
        $this->queueTokenResponse();
        $this->transport->queueError(401, 'unauthorized');

        try {
            $this->client->getQuota();
            self::fail('A second refusal should not be retried.');
        } catch (AuthenticationException $exception) {
            self::assertSame(ErrorCode::UNAUTHORIZED, $exception->errorCode);
        }
        self::assertCount(3, $this->transport->requests());
        self::assertSame(0, $this->transport->pendingCount());
    }

    #[Test]
    public function itRetriesAWriteToo(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(401, 'unauthorized');
        $this->queueTokenResponse();
        $this->transport->queueData(['status' => 'sent']);

        $this->client->resendInvite('tenant-1', 'user-1');

        $requests = $this->transport->requests();
        self::assertCount(3, $requests);
        self::assertSame('POST', $requests[2]->method);
        self::assertSame($requests[0]->header('X-Request-Id'), $requests[2]->header('X-Request-Id'));
    }

    #[Test]
    public function itReusesATokenAnotherProcessAlreadyReplaced(): void
    {
        $store = new RacingTokenStore(
            new AccessToken('other-process.jwt', self::ALL_SCOPES, $this->clock->now() + 3600, ApiFixtures::SLUG),
        );
        $store->put(
            $this->fingerprint(),
            new AccessToken(self::STORED_TOKEN, self::ALL_SCOPES, $this->clock->now() + 3600, ApiFixtures::SLUG),
        );
        $client = new ResellerClient($this->config, $this->transport, $store, $this->clock, $this->sleeper);
        $this->transport->queueError(401, 'unauthorized');
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $client->getQuota();

        $requests = $this->transport->requests();
        self::assertCount(2, $requests);
        self::assertSame('Bearer other-process.jwt', $requests[1]->header('Authorization'));
    }

    #[Test]
    public function itDoesNotRetryARefusalThatIsNotAboutTheToken(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(403, 'insufficient_scope');

        try {
            $this->client->getQuota();
            self::fail('Expected a ForbiddenException.');
        } catch (ForbiddenException $exception) {
            self::assertSame(ErrorCode::INSUFFICIENT_SCOPE, $exception->errorCode);
        }
        self::assertCount(1, $this->transport->requests());
    }

    #[Test]
    public function itReportsARevokedKeyWhenTheReplacementMintIsRefused(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(401, 'unauthorized');
        $this->transport->queueJson(401, ['error' => 'invalid_client']);

        try {
            $this->client->getQuota();
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $exception) {
            self::assertSame(ErrorCode::INVALID_CLIENT, $exception->errorCode);
        }
        self::assertNull($this->store->get($this->fingerprint()));
        self::assertNotNull($this->store->blockedUntil($this->fingerprint()));
    }
}
