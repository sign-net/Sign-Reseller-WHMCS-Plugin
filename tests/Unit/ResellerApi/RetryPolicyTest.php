<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Model\PackageUpdate;
use SignNet\ResellerApi\Model\ProvisionRequest;

final class RetryPolicyTest extends ClientTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->storeUsableToken();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatuses(): iterable
    {
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
    }

    #[Test]
    #[DataProvider('retryableStatuses')]
    public function itRetriesAGetTwiceOnAGatewayError(int $status): void
    {
        $this->transport->queue(self::unavailable($status), self::unavailable($status));
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $this->client->getQuota();

        self::assertCount(3, $this->transport->requests());
        $this->assertBackoff(2);
    }

    #[Test]
    public function itGivesUpOnAGetAfterTwoRetries(): void
    {
        $this->transport->queue(self::unavailable(503), self::unavailable(503), self::unavailable(503));

        try {
            $this->client->getQuota();
            self::fail('Expected a ServerException.');
        } catch (ServerException $exception) {
            self::assertSame(503, $exception->httpStatus);
        }
        self::assertCount(3, $this->transport->requests());
        $this->assertBackoff(2);
    }

    /**
     * @return iterable<string, array{bool|null}>
     */
    public static function anyRequestSent(): iterable
    {
        yield 'not sent' => [false];
        yield 'sent' => [true];
        yield 'unknown' => [null];
    }

    #[Test]
    #[DataProvider('anyRequestSent')]
    public function itRetriesAGetAfterAnyTransportFailure(?bool $requestSent): void
    {
        $this->transport->queue(new TransportException('timed out', $requestSent));
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $this->client->getQuota();

        self::assertCount(2, $this->transport->requests());
        $this->assertBackoff(1);
    }

    #[Test]
    public function itRetriesACatalogueSearchAsItRetriesAGet(): void
    {
        $this->transport->queue(new TransportException('reset', true), self::unavailable(503));
        $this->transport->queueData(ApiFixtures::page('packages', [ApiFixtures::packageSummary()]));

        self::assertCount(1, $this->client->listPackages());
        self::assertCount(3, $this->transport->requests());
        $this->assertBackoff(2);
    }

    #[Test]
    public function itRetriesReadingAnAddonAfterALostAnswer(): void
    {
        $this->transport->queue(new TransportException('timed out', null));
        $this->transport->queueData(ApiFixtures::addon());

        $this->client->getAddon('add-1');

        self::assertCount(2, $this->transport->requests());
    }

    #[Test]
    public function itNeverRetriesAPackageUpdateThatMayHaveReachedTheServer(): void
    {
        $this->transport->queue(new TransportException('timed out', true));

        $this->expectException(TransportException::class);

        try {
            $this->client->updatePackage('pkg-1', (new PackageUpdate())->withName('Pro'));
        } finally {
            self::assertCount(1, $this->transport->requests());
        }
    }

    #[Test]
    public function itSendsTheSameRequestIdOnEveryRetry(): void
    {
        $this->transport->queue(new TransportException('reset', true), self::unavailable(502));
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $this->client->getQuota();

        $ids = array_map(
            static fn (Request $request): ?string => $request->header('X-Request-Id'),
            $this->transport->requests(),
        );
        self::assertCount(1, array_unique($ids));
    }

    #[Test]
    public function itDoesNotRetryAGetOnAnInternalServerError(): void
    {
        $this->transport->queue(self::unavailable(500));

        $this->expectException(ServerException::class);

        try {
            $this->client->getQuota();
        } finally {
            self::assertCount(1, $this->transport->requests());
            self::assertSame([], $this->sleeper->sleeps());
        }
    }

    #[Test]
    public function itNeverRetriesARateLimitedCall(): void
    {
        $this->transport->queueJson(
            429,
            ['error' => 'Too many requests, please try again later.'],
            ['Retry-After' => '1'],
        );

        $this->expectException(RateLimitedException::class);

        try {
            $this->client->getQuota();
        } finally {
            self::assertCount(1, $this->transport->requests());
            self::assertSame([], $this->sleeper->sleeps());
        }
    }

    #[Test]
    public function itRetriesAWriteOnceWhenTheRequestNeverLeft(): void
    {
        $this->transport->queue(new TransportException('connection refused', false));
        $this->transport->queueData(['status' => 'sent']);

        $this->client->resendInvite('tenant-1', 'user-1');

        self::assertCount(2, $this->transport->requests());
        $this->assertBackoff(1);
    }

    #[Test]
    public function itRetriesADeleteOnceWhenTheRequestNeverLeft(): void
    {
        $this->transport->queue(new TransportException('connection refused', false));
        $this->transport->queueData(['status' => 'removed']);

        $this->client->removeUser('tenant-1', 'user-1');

        self::assertCount(2, $this->transport->requests());
    }

    #[Test]
    public function itRetriesAWriteOnlyOnce(): void
    {
        $this->transport->queue(
            new TransportException('connection refused', false),
            new TransportException('connection refused', false),
        );

        $this->expectException(TransportException::class);

        try {
            $this->client->resendInvite('tenant-1', 'user-1');
        } finally {
            self::assertCount(2, $this->transport->requests());
        }
    }

    /**
     * @return iterable<string, array{bool|null}>
     */
    public static function possiblySent(): iterable
    {
        yield 'sent' => [true];
        yield 'unknown' => [null];
    }

    #[Test]
    #[DataProvider('possiblySent')]
    public function itNeverRetriesAWriteThatMayHaveReachedTheServer(?bool $requestSent): void
    {
        $this->transport->queue(new TransportException('timed out', $requestSent));

        $this->expectException(TransportException::class);

        try {
            $this->client->provisionPrivateLabel(
                new ProvisionRequest('a@b.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme'),
            );
        } finally {
            self::assertCount(1, $this->transport->requests());
            self::assertSame([], $this->sleeper->sleeps());
        }
    }

    #[Test]
    public function itNeverRetriesAWriteOnAGatewayError(): void
    {
        $this->transport->queue(self::unavailable(503));

        $this->expectException(ServerException::class);

        try {
            $this->client->resendInvite('tenant-1', 'user-1');
        } finally {
            self::assertCount(1, $this->transport->requests());
        }
    }

    private function assertBackoff(int $expectedPauses): void
    {
        $sleeps = $this->sleeper->sleeps();
        self::assertCount($expectedPauses, $sleeps);
        $bases = [500, 1500];
        foreach ($sleeps as $index => $milliseconds) {
            self::assertGreaterThanOrEqual($bases[$index], $milliseconds);
            self::assertLessThanOrEqual($bases[$index] + 250, $milliseconds);
        }
    }

    private static function unavailable(int $status): Response
    {
        return new Response($status, ['Content-Type' => 'text/html'], '<html>unavailable</html>');
    }
}
