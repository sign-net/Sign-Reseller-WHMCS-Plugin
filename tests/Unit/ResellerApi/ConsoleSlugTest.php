<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Auth\Scope;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Model\ProvisionRequest;

/**
 * How the client finds and keeps the reseller's console slug, which addresses every reseller call.
 */
final class ConsoleSlugTest extends ClientTestCase
{
    private const TOKEN_URL = self::BASE_URL . '/api/v1/auth/token';
    private const DASHBOARD_URL = self::BASE_URL . '/console/dashboard';

    #[Test]
    public function itLearnsTheSlugOnceAndKeepsItWithTheToken(): void
    {
        $this->queueTokenResponse();
        $this->queueDashboard();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        $this->client->getQuota();
        $this->client->getQuota();

        self::assertSame(
            [self::TOKEN_URL, self::DASHBOARD_URL, self::apiUrl('/billing/quota'), self::apiUrl('/billing/quota')],
            $this->urls(),
        );
        self::assertSame(ApiFixtures::SLUG, $this->store->get($this->fingerprint())?->slug);
    }

    #[Test]
    public function itLearnsTheSlugForATokenStoredWithoutOne(): void
    {
        $this->storeUsableToken(slug: null);
        $this->queueDashboard();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        $this->client->getQuota();

        self::assertSame([self::DASHBOARD_URL, self::apiUrl('/billing/quota')], $this->urls());
        self::assertSame(ApiFixtures::SLUG, $this->store->get($this->fingerprint())?->slug);
    }

    #[Test]
    public function aKeyThatCannotReadKeepsItsTokenWhenTheSlugIsRefused(): void
    {
        $this->transport->queueJson(200, [
            'access_token' => self::MINTED_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => Scope::PROVISION,
        ]);
        $this->transport->queueError(403, 'insufficient_scope');
        $this->transport->queueError(403, 'insufficient_scope');

        $first = $this->forbidden(fn () => $this->client->getQuota());
        $second = $this->forbidden(fn () => $this->client->getQuota());

        self::assertSame('insufficient_scope', $first->errorCode);
        self::assertSame('insufficient_scope', $second->errorCode);
        self::assertSame(
            [self::TOKEN_URL, self::DASHBOARD_URL, self::DASHBOARD_URL],
            $this->urls(),
        );
    }

    #[Test]
    public function itFollowsARenamedResellerHostOnce(): void
    {
        $this->storeUsableToken(slug: 'old.sign.test');
        $this->transport->queueError(403, 'forbidden');
        $this->queueDashboard();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        $this->client->getQuota();

        self::assertSame(
            [
                self::BASE_URL . '/console/old.sign.test/reseller/billing/quota',
                self::DASHBOARD_URL,
                self::apiUrl('/billing/quota'),
            ],
            $this->urls(),
        );
        self::assertSame(ApiFixtures::SLUG, $this->store->get($this->fingerprint())?->slug);
    }

    #[Test]
    public function aRefusalUnderTheCurrentSlugStands(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(403, 'forbidden');
        $this->queueDashboard();

        $refusal = $this->forbidden(fn () => $this->client->getPrivateLabel(ApiFixtures::TENANT_ID));

        self::assertSame('forbidden', $refusal->errorCode);
        self::assertSame(
            [self::apiUrl('/private-labels/' . ApiFixtures::TENANT_ID), self::DASHBOARD_URL],
            $this->urls(),
        );
    }

    #[Test]
    public function aRenamedHostIsFollowedNoFurtherThanOnce(): void
    {
        $this->storeUsableToken(slug: 'old.sign.test');
        $this->transport->queueError(403, 'forbidden');
        $this->queueDashboard();
        $this->transport->queueError(403, 'forbidden');

        $this->forbidden(fn () => $this->client->getPrivateLabel(ApiFixtures::TENANT_ID));

        self::assertCount(3, $this->transport->requests());
    }

    #[Test]
    public function otherRefusalsDoNotLookTheSlugUpAgain(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(403, 'insufficient_scope');

        $this->forbidden(fn () => $this->client->getQuota());

        self::assertSame([self::apiUrl('/billing/quota')], $this->urls());
    }

    #[Test]
    public function itRefusesASlugThatIsNotAHostname(): void
    {
        $this->storeUsableToken(slug: null);
        $this->queueDashboard('../admin');

        $this->expectException(ServerException::class);

        $this->client->getQuota();
    }

    #[Test]
    public function aSlugThatCannotBeLearntLeavesTheCallUnsent(): void
    {
        $this->storeUsableToken(slug: null);
        $lostAnswer = new TransportException('Connection reset by peer', true, 'request-1');
        $this->transport->queue($lostAnswer, $lostAnswer, $lostAnswer);

        try {
            $this->client->provisionPrivateLabel(
                new ProvisionRequest('owner@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme'),
            );
            self::fail('Provisioning went ahead without the slug.');
        } catch (TransportException $failure) {
            self::assertFalse($failure->requestSent);
            self::assertStringContainsString('Could not learn the reseller console address', $failure->getMessage());
        }
        self::assertSame([self::DASHBOARD_URL, self::DASHBOARD_URL, self::DASHBOARD_URL], $this->urls());
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        return array_map(static fn ($request): string => $request->url, $this->transport->requests());
    }

    private function forbidden(callable $call): ForbiddenException
    {
        try {
            $call();
        } catch (ForbiddenException $refusal) {
            return $refusal;
        }
        self::fail('Expected a ForbiddenException.');
    }
}
