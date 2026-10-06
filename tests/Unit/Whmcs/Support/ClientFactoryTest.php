<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsSchema;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\ClientFactory;

final class ClientFactoryTest extends ModuleTestCase
{
    /**
     * @return iterable<string, array{string, bool, string, string}>
     */
    public static function serverRecords(): iterable
    {
        yield 'bare host over SSL' => ['api-app.sign.net', true, '', 'https://api-app.sign.net'];
        yield 'default SSL port' => ['api-app.sign.net', true, '443', 'https://api-app.sign.net'];
        yield 'custom port' => ['api-app.sign.net', true, '8443', 'https://api-app.sign.net:8443'];
        yield 'pasted URL with a path' => ['https://api-app.sign.net/', true, '', 'https://api-app.sign.net'];
        yield 'local backend over http' => ['http://localhost:8080', false, '', 'http://localhost:8080'];
    }

    #[Test]
    #[DataProvider('serverRecords')]
    public function itCallsTheApiAtTheServersAddress(string $hostname, bool $secure, string $port, string $origin): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        ClientFactory::fromParams($this->params([
            'serverhostname' => $hostname,
            'serversecure' => $secure,
            'serverport' => $port,
        ]))->getQuota();

        self::assertSame($origin . R::API . '/billing/quota', $this->transport->lastRequest()->url);
    }

    #[Test]
    public function itReadsTheKeyFromAStoredServerRecord(): void
    {
        $serverId = WhmcsSchema::insertServer(self::API_KEY, 'api.other.test');
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());

        ClientFactory::fromServerId($serverId)->getQuota();

        $mint = $this->requestTo(R::TOKEN_CALL);
        self::assertSame('https://api.other.test/api/v1/auth/token', $mint->url);
        self::assertSame(self::API_KEY, self::bodyOf($mint)['api_key']);
    }

    #[Test]
    public function itRefusesAServerWithoutAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password field');

        ClientFactory::fromParams($this->params(['serverpassword' => '']));
    }

    #[Test]
    public function itRefusesAnUnknownServer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClientFactory::fromServerId(999);
    }
}
