<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

use SignNet\ResellerApi\Http\Request;
use SignNet\Tests\Unit\ResellerApi\Fake\FakeSleeper;
use SignNet\Tests\Unit\ResellerApi\Fake\FakeTransport;
use SignNet\Whmcs\ClientFactory;
use WHMCS\Database\Capsule;

/**
 * A WHMCS database with one Sign.net server, and a fake Sign.net behind every client the plugin
 * builds.
 */
abstract class ModuleTestCase extends DatabaseTestCase
{
    protected const API_KEY = 'snk_test_0123456789abcdef0123456789abcdef_S3cr3tS3cr3tS3cr3t';
    protected const API_SECRET = 'S3cr3tS3cr3tS3cr3t';
    protected const API_HOST = 'api.sign.test';
    protected const ACCESS_TOKEN = 'minted.jwt.token';
    protected const SERVICE_ID = 101;
    protected const CLIENT_ID = 7;

    /** What a run that starts without an access token calls first: a token, then the reseller's slug. */
    protected const FIRST_CALLS = [SignNetResponses::TOKEN_CALL, SignNetResponses::DASHBOARD_CALL];

    protected FakeTransport $transport;
    protected FakeSleeper $sleeper;
    protected int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
        $this->sleeper = new FakeSleeper();
        ClientFactory::useTestDoubles($this->transport, $this->sleeper);
        $this->serverId = WhmcsSchema::insertServer(self::API_KEY, self::API_HOST);
    }

    protected function tearDown(): void
    {
        ClientFactory::useTestDoubles(null);
        parent::tearDown();
    }

    /**
     * Sign.net's answers to the first calls: minting the access token, then the console dashboard
     * that names the reseller's slug.
     */
    protected function queueToken(): void
    {
        $this->transport->queueJson(200, [
            'access_token' => self::ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'reseller:read reseller:provision reseller:packages',
        ]);
        $this->transport->queueData(SignNetResponses::dashboard());
    }

    /**
     * WHMCS module parameters for service 101 of client 7 on the Sign.net server.
     *
     * @param array<string, mixed> $overrides Replaces keys, recursively for nested arrays.
     *
     * @return array<string, mixed>
     */
    protected function params(array $overrides = []): array
    {
        return array_replace_recursive([
            'serviceid' => self::SERVICE_ID,
            'userid' => self::CLIENT_ID,
            'pid' => 3,
            'serverid' => $this->serverId,
            'domain' => '',
            'username' => '',
            'serverhostname' => self::API_HOST,
            'serversecure' => true,
            'serverport' => '',
            'serverpassword' => self::API_KEY,
            'configoption1' => 'pkg-1',
            'configoption2' => 'hold',
            'configoption3' => 'cancel_requests',
            'customfields' => ['Portal address' => 'sign.acme.test', 'Portal name' => 'Acme Sign'],
            'configoptions' => [],
            'clientsdetails' => [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'email' => 'ada@acme.test',
                'companyname' => 'Acme',
            ],
        ], $overrides);
    }

    protected function insertService(int $serviceId = self::SERVICE_ID, string $status = 'Pending'): void
    {
        Capsule::table('tblhosting')->insert([
            'id' => $serviceId,
            'userid' => self::CLIENT_ID,
            'packageid' => 3,
            'server' => $this->serverId,
            'domainstatus' => $status,
        ]);
    }

    /**
     * @return list<string> "METHOD /path" of every request Sign.net received, token calls included.
     */
    protected function calls(): array
    {
        return array_map(
            static fn (Request $request): string => $request->method . ' ' . $request->target(),
            $this->transport->requests(),
        );
    }

    /**
     * @return list<Request> The requests Sign.net received for $call ("METHOD /path"), oldest first.
     */
    protected function requestsTo(string $call): array
    {
        return array_values(array_filter(
            $this->transport->requests(),
            static fn (Request $request): bool => $request->method . ' ' . $request->target() === $call,
        ));
    }

    /**
     * The one request Sign.net received for $call ("METHOD /path").
     */
    protected function requestTo(string $call): Request
    {
        $requests = $this->requestsTo($call);
        self::assertCount(1, $requests, sprintf('Expected one %s.', $call));

        return $requests[0];
    }

    /**
     * @return array<mixed> The JSON body of the one request Sign.net received for $call.
     */
    protected function bodySentTo(string $call): array
    {
        return self::bodyOf($this->requestTo($call));
    }

    /**
     * @return array<mixed>
     */
    protected static function bodyOf(Request $request): array
    {
        $decoded = json_decode($request->body ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected static function assertNoSecretsLogged(): void
    {
        $log = WhmcsFake::moduleLogText();
        self::assertStringNotContainsString(self::API_SECRET, $log);
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $log);
    }
}
