<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\RateLimitedException;

final class PausedMintingTest extends ClientTestCase
{
    #[Test]
    public function anOrdinaryCallFailsWithoutNetworkWhileMintingIsPaused(): void
    {
        $this->store->block($this->fingerprint(), $this->clock->now() + 300, ErrorCode::INVALID_CLIENT);

        try {
            $this->client->getQuota();
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $exception) {
            self::assertSame(ErrorCode::INVALID_CLIENT, $exception->errorCode);
        }
        self::assertSame([], $this->transport->requests());
    }

    #[Test]
    public function aRefusedTokenIsDroppedButNotReplacedWhileMintingIsPaused(): void
    {
        $this->storeUsableToken();
        $this->store->block($this->fingerprint(), $this->clock->now() + 300, 'rate_limited');
        $this->transport->queueError(401, 'unauthorized');

        try {
            $this->client->getQuota();
            self::fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $exception) {
            self::assertNull($exception->httpStatus);
        }
        self::assertCount(1, $this->transport->requests());
        self::assertNull($this->store->get($this->fingerprint()));
    }
}
