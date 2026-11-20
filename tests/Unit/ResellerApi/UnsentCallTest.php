<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Model\ProvisionRequest;

/**
 * A call whose access token cannot be had never leaves, and is reported that way, so a caller that
 * must not repeat a write can tell it from a lost answer.
 */
final class UnsentCallTest extends ClientTestCase
{
    private const TOKEN_URL = self::BASE_URL . '/api/v1/auth/token';

    #[Test]
    public function aTokenTheServerFailsToMintLeavesTheCallUnsent(): void
    {
        $this->transport->queueJson(500, ['error' => 'server_error']);

        $failure = $this->unsentProvisioning();

        self::assertStringStartsWith('Could not get an access token: ', $failure->getMessage());
        self::assertSame([self::TOKEN_URL], $this->urls());
    }

    #[Test]
    public function aTokenRequestThatGetsNoAnswerLeavesTheCallUnsent(): void
    {
        $this->transport->queue(new TransportException('Operation timed out', true, 'request-1'));

        $failure = $this->unsentProvisioning();

        self::assertSame('request-1', $failure->requestId, 'The id names the token call in the module log.');
        self::assertSame([self::TOKEN_URL], $this->urls());
    }

    #[Test]
    public function aRefusedKeyIsStillReportedAsRefused(): void
    {
        $this->transport->queueJson(401, ['error' => 'invalid_client']);

        try {
            $this->provision();
            self::fail('Provisioning went ahead without a token.');
        } catch (AuthenticationException $refusal) {
            self::assertSame(ErrorCode::INVALID_CLIENT, $refusal->errorCode);
        }
    }

    #[Test]
    public function aRateLimitedTokenEndpointIsStillReportedAsRateLimited(): void
    {
        $this->transport->queueJson(429, ['error' => 'rate_limited'], ['Retry-After' => '60']);

        $this->expectException(RateLimitedException::class);

        $this->provision();
    }

    #[Test]
    public function aTokenThatCannotBeReplacedAfterARefusalLeavesTheCallUnsent(): void
    {
        $this->storeUsableToken();
        $this->transport->queueError(401, 'unauthorized');
        $this->transport->queueJson(503, ['error' => 'unavailable']);

        $failure = $this->unsentProvisioning();

        self::assertStringStartsWith('Could not replace the access token Sign.net refused: ', $failure->getMessage());
        self::assertSame([self::apiUrl('/private-labels'), self::TOKEN_URL], $this->urls());
    }

    private function unsentProvisioning(): TransportException
    {
        try {
            $this->provision();
        } catch (TransportException $failure) {
            self::assertFalse($failure->requestSent, 'The provisioning call never left.');

            return $failure;
        }
        self::fail('Provisioning went ahead without a token.');
    }

    private function provision(): void
    {
        $this->client->provisionPrivateLabel(
            new ProvisionRequest('owner@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme'),
        );
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        return array_map(static fn ($request): string => $request->url, $this->transport->requests());
    }
}
