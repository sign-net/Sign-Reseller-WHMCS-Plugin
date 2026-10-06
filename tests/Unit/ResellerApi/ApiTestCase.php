<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Auth\AccessToken;
use SignNet\ResellerApi\Auth\InMemoryTokenStore;
use SignNet\ResellerApi\Auth\Scope;
use SignNet\ResellerApi\ClientConfig;
use SignNet\ResellerApi\Http\Request;
use SignNet\Tests\Unit\ResellerApi\Fake\FakeClock;
use SignNet\Tests\Unit\ResellerApi\Fake\FakeSleeper;
use SignNet\Tests\Unit\ResellerApi\Fake\FakeTransport;

/**
 * Fakes and helpers shared by tests that talk to the API through the fake transport.
 */
abstract class ApiTestCase extends TestCase
{
    protected const API_KEY = 'snk_test_0123456789abcdef0123456789abcdef_S3cr3tS3cr3tS3cr3t';
    protected const API_SECRET = 'S3cr3tS3cr3tS3cr3t';
    protected const BASE_URL = 'https://api.example.test';
    protected const STORED_TOKEN = 'stored.jwt.token';
    protected const MINTED_TOKEN = 'minted.jwt.token';
    protected const ALL_SCOPES = [Scope::READ, Scope::PROVISION, Scope::PACKAGES];

    /** Where the reseller's console endpoints live, once its slug is known. */
    protected const API_PATH = '/console/' . ApiFixtures::SLUG . '/reseller';

    protected FakeTransport $transport;
    protected InMemoryTokenStore $store;
    protected FakeClock $clock;
    protected FakeSleeper $sleeper;
    protected ClientConfig $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->store = new InMemoryTokenStore();
        $this->clock = new FakeClock();
        $this->sleeper = new FakeSleeper();
        $this->config = new ClientConfig(self::BASE_URL, self::API_KEY);
    }

    protected function fingerprint(): string
    {
        return $this->config->fingerprint();
    }

    /**
     * A token with more than an hour left, stored with the reseller's slug unless $slug is null.
     */
    protected function storeUsableToken(string $token = self::STORED_TOKEN, ?string $slug = ApiFixtures::SLUG): void
    {
        $this->store->put(
            $this->fingerprint(),
            new AccessToken($token, self::ALL_SCOPES, $this->clock->now() + 3600, $slug),
        );
    }

    /**
     * The console dashboard's answer, which names the reseller's slug.
     */
    protected function queueDashboard(string $slug = ApiFixtures::SLUG): void
    {
        $this->transport->queueData(ApiFixtures::dashboard($slug));
    }

    /**
     * The full URL of a console endpoint, from its path after /console/{slug}/reseller.
     */
    protected static function apiUrl(string $path): string
    {
        return self::BASE_URL . self::API_PATH . $path;
    }

    protected function queueTokenResponse(string $token = self::MINTED_TOKEN, int $expiresIn = 3600): void
    {
        $this->transport->queueJson(200, [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => implode(' ', self::ALL_SCOPES),
        ]);
    }

    /**
     * @return array<mixed>
     */
    protected static function bodyOf(Request $request): array
    {
        if ($request->body === null) {
            self::fail(sprintf('%s %s has no body.', $request->method, $request->url));
        }
        $decoded = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            self::fail('The request body is not a JSON object.');
        }

        return $decoded;
    }

    protected static function assertNoSecretsIn(string $text): void
    {
        self::assertStringNotContainsString(self::API_SECRET, $text);
        self::assertStringNotContainsString(self::STORED_TOKEN, $text);
        self::assertStringNotContainsString(self::MINTED_TOKEN, $text);
    }
}
