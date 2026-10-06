<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Support;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\ClientFactory;
use SignNet\Whmcs\Support\LoggingTransport;

final class LoggingTransportTest extends ModuleTestCase
{
    #[Test]
    public function itLogsEveryCallWithoutTheKeyOrTheToken(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        ClientFactory::fromParams($this->params())->getQuota();

        self::assertCount(3, WhmcsFake::$moduleCalls);
        self::assertSame('signnet', WhmcsFake::$moduleCalls[0]['module']);
        self::assertStringStartsWith(R::TOKEN_CALL, WhmcsFake::$moduleCalls[0]['action']);
        self::assertStringStartsWith(R::DASHBOARD_CALL, WhmcsFake::$moduleCalls[1]['action']);
        self::assertStringStartsWith(R::QUOTA_CALL, WhmcsFake::$moduleCalls[2]['action']);
        self::assertStringContainsString('HTTP 200', WhmcsFake::$moduleCalls[2]['response']);
        self::assertStringContainsString('Reseller Pro', WhmcsFake::$moduleCalls[2]['response']);
        self::assertNoSecretsLogged();
    }

    #[Test]
    public function itMasksSecretFieldsInJson(): void
    {
        $masked = LoggingTransport::mask(
            '{"api_key":"snk_x","access_token":"a.b.c","confirmation":{"key":"k-1"},'
            . '"confirmationKey":"k-2","name":"x"}',
        );

        self::assertSame(
            '{"api_key":"****","access_token":"****","confirmation":{"key":"****"},'
            . '"confirmationKey":"****","name":"x"}',
            $masked,
        );
    }
}
