<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\ClientConfig;
use SignNet\ResellerApi\Model\AddonInput;
use SignNet\ResellerApi\Model\AddonItem;
use SignNet\ResellerApi\Model\AddonUpdate;
use SignNet\ResellerApi\Model\BillingCycle;
use SignNet\ResellerApi\Model\ItemCode;
use SignNet\ResellerApi\Model\PackageInput;
use SignNet\ResellerApi\Model\PackageItem;
use SignNet\ResellerApi\Model\PackageUpdate;
use SignNet\ResellerApi\Model\ProvisionRequest;
use SignNet\ResellerApi\ResellerClient;

final class RequestSerializationTest extends ClientTestCase
{
    private const T = ApiFixtures::TENANT_ID;
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeUsableToken();
    }

    /**
     * @return iterable<string, array{\Closure(ResellerClient): mixed, string, string, array<mixed>|null, array<mixed>}>
     */
    public static function endpoints(): iterable
    {
        $pl = '/private-labels/' . self::T;
        $everything = ['query' => '', 'page' => 1, 'pageSize' => 100];

        yield 'list private labels' => [
            static fn (ResellerClient $client) => $client->listPrivateLabels(),
            'GET', '/private-labels', null, ['privateLabels' => [], 'provisionedCount' => 0, 'period' => '2026-07'],
        ];
        yield 'get private label' => [
            static fn (ResellerClient $client) => $client->getPrivateLabel(self::T),
            'GET', $pl, null, ApiFixtures::privateLabel(),
        ];
        yield 'deprovision' => [
            static fn (ResellerClient $client) => $client->deprovisionPrivateLabel(self::T),
            'DELETE', $pl, null, ['status' => 'deprovisioned'],
        ];
        yield 'suspend without reason' => [
            static fn (ResellerClient $client) => $client->suspendPrivateLabel(self::T),
            'POST', $pl . '/suspend', [], ApiFixtures::suspensionState(),
        ];
        yield 'suspend with reason' => [
            static fn (ResellerClient $client) => $client->suspendPrivateLabel(self::T, 'Unpaid invoice'),
            'POST', $pl . '/suspend', ['reason' => 'Unpaid invoice'], ApiFixtures::suspensionState(),
        ];
        yield 'unsuspend' => [
            static fn (ResellerClient $client) => $client->unsuspendPrivateLabel(self::T),
            'POST', $pl . '/unsuspend', [], ApiFixtures::suspensionState(),
        ];
        yield 'attach domain' => [
            static fn (ResellerClient $client) => $client->attachDomain(self::T),
            'POST', $pl . '/domain/attach', [], ['domainSetup' => ApiFixtures::domainSetup()],
        ];
        yield 'list users' => [
            static fn (ResellerClient $client) => $client->listUsers(self::T),
            'GET', $pl . '/members', null, ['members' => [ApiFixtures::user()]],
        ];
        yield 'add user' => [
            static fn (ResellerClient $client) => $client->addUser(self::T, 'bo@acme.test', 'Bo', 'Chan'),
            'POST', $pl . '/users', ['email' => 'bo@acme.test', 'firstName' => 'Bo', 'lastName' => 'Chan'],
            ['status' => 'created', 'userID' => 'u-2', 'created' => true],
        ];
        yield 'remove user' => [
            static fn (ResellerClient $client) => $client->removeUser(self::T, 'u-2'),
            'DELETE', $pl . '/users/u-2', null, ['status' => 'removed'],
        ];
        yield 'resend invite' => [
            static fn (ResellerClient $client) => $client->resendInvite(self::T, 'u-2'),
            'POST', $pl . '/users/u-2/invite', [], ['status' => 'sent'],
        ];
        yield 'list packages' => [
            static fn (ResellerClient $client) => $client->listPackages(),
            'POST', '/billing/packages/search', $everything,
            ApiFixtures::page('packages', [ApiFixtures::packageSummary()]),
        ];
        yield 'get package' => [
            static fn (ResellerClient $client) => $client->getPackage('pkg-1'),
            'POST', '/billing/packages/get', ['id' => 'pkg-1'], ApiFixtures::package(),
        ];
        yield 'list add-ons' => [
            static fn (ResellerClient $client) => $client->listAddons(),
            'POST', '/billing/addons/search', $everything, ApiFixtures::page('addons', [ApiFixtures::addonSummary()]),
        ];
        yield 'get add-on' => [
            static fn (ResellerClient $client) => $client->getAddon('add-1'),
            'POST', '/billing/addons/get', ['id' => 'add-1'], ApiFixtures::addon(),
        ];
        yield 'get assignment' => [
            static fn (ResellerClient $client) => $client->getAssignment(self::T),
            'GET', $pl . '/package', null, ['assignment' => null],
        ];
        yield 'assign package' => [
            static fn (ResellerClient $client) => $client->assignPackage(self::T, 'pkg-1'),
            'POST', $pl . '/package', ['packageId' => 'pkg-1'],
            ['outcome' => 'Assigned', 'assignmentId' => 'asg-1', 'allocated' => ['seats' => 10]],
        ];
        yield 'assign package with confirmation' => [
            static fn (ResellerClient $client) => $client->assignPackage(self::T, 'pkg-1', 'confirm-key'),
            'POST', $pl . '/package', ['packageId' => 'pkg-1', 'confirmationKey' => 'confirm-key'],
            ['outcome' => 'Assigned', 'assignmentId' => 'asg-1', 'allocated' => ['seats' => 10]],
        ];
        yield 'unassign package' => [
            static fn (ResellerClient $client) => $client->unassignPackage(self::T),
            'DELETE', $pl . '/package', null, ['outcome' => 'Unassigned'],
        ];
        yield 'attach add-on' => [
            static fn (ResellerClient $client) => $client->attachAddon(self::T, 'add-1', 2),
            'POST', $pl . '/package/addons', ['addonId' => 'add-1', 'quantity' => 2],
            ['outcome' => 'Attached', 'subscriptionAddonId' => 'sa-1'],
        ];
        yield 'attach add-on with confirmation' => [
            static fn (ResellerClient $client) => $client->attachAddon(self::T, 'add-1', 2, 'confirm-key'),
            'POST', $pl . '/package/addons',
            ['addonId' => 'add-1', 'quantity' => 2, 'confirmationKey' => 'confirm-key'],
            ['outcome' => 'Attached', 'subscriptionAddonId' => 'sa-1'],
        ];
        yield 'remove add-on' => [
            static fn (ResellerClient $client) => $client->removeAddon(self::T, 'sa-1'),
            'DELETE', $pl . '/package/addons/sa-1', null, ['outcome' => 'Removed'],
        ];
        yield 'quota' => [
            static fn (ResellerClient $client) => $client->getQuota(),
            'GET', '/billing/quota', null, ApiFixtures::quotaWithPlan(),
        ];
        yield 'usage for the current month' => [
            static fn (ResellerClient $client) => $client->getUsage(),
            'GET', '/usage', null, ApiFixtures::usage(),
        ];
        yield 'usage for a month' => [
            static fn (ResellerClient $client) => $client->getUsage('2026-07'),
            'GET', '/usage?period=2026-07', null, ApiFixtures::usage(),
        ];
    }

    /**
     * @param \Closure(ResellerClient): mixed $call
     * @param array<mixed>|null $expectedBody
     * @param array<mixed> $answer
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function itSendsEachEndpointAsDocumented(
        \Closure $call,
        string $expectedMethod,
        string $expectedPath,
        ?array $expectedBody,
        array $answer,
    ): void {
        $this->transport->queueData($answer);

        $call($this->client);

        $request = $this->transport->lastRequest();
        self::assertSame($expectedMethod, $request->method);
        self::assertSame(self::apiUrl($expectedPath), $request->url);
        if ($expectedBody === null) {
            self::assertNull($request->body);
            self::assertNull($request->header('Content-Type'));
        } else {
            self::assertSame($expectedBody, self::bodyOf($request));
            self::assertSame('application/json', $request->header('Content-Type'));
        }
        self::assertSame('Bearer ' . self::STORED_TOKEN, $request->header('Authorization'));
        self::assertSame(0, $this->transport->pendingCount());
    }

    #[Test]
    public function itSendsAnEmptyObjectWhenAPostHasNoFields(): void
    {
        $this->transport->queueData(ApiFixtures::suspensionState());

        $this->client->unsuspendPrivateLabel(self::T);

        self::assertSame('{}', $this->transport->lastRequest()->body);
    }

    #[Test]
    public function itIdentifiesEveryRequest(): void
    {
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $this->client->getQuota();
        $this->client->getQuota();

        [$first, $second] = $this->transport->requests();
        self::assertSame(ClientConfig::DEFAULT_USER_AGENT, $first->header('User-Agent'));
        self::assertSame('application/json', $first->header('Accept'));
        self::assertMatchesRegularExpression(self::UUID_V4, (string) $first->header('X-Request-Id'));
        self::assertNotSame($first->header('X-Request-Id'), $second->header('X-Request-Id'));
    }

    #[Test]
    public function itUsesTheConfiguredUserAgentAndTimeouts(): void
    {
        $config = new ClientConfig(self::BASE_URL, self::API_KEY, 'SignNet-WHMCS/1.0', 5, 20, 120);
        $client = new ResellerClient($config, $this->transport, $this->store, $this->clock, $this->sleeper);
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());
        $this->transport->queueData(ApiFixtures::provisionResult());

        $client->getQuota();
        $client->provisionPrivateLabel(self::minimalProvision());

        [$quota, $provision] = $this->transport->requests();
        self::assertSame('SignNet-WHMCS/1.0', $quota->header('User-Agent'));
        self::assertSame(20, $quota->timeoutSeconds);
        self::assertSame(5, $quota->connectTimeoutSeconds);
        self::assertSame(120, $provision->timeoutSeconds);
    }

    #[Test]
    public function itGivesProvisioningTheLongerTimeout(): void
    {
        $this->transport->queueData(ApiFixtures::provisionResult());
        $this->transport->queueData(['privateLabels' => [], 'provisionedCount' => 0, 'period' => '2026-07']);

        $this->client->provisionPrivateLabel(self::minimalProvision());
        $this->client->listPrivateLabels();

        [$provision, $list] = $this->transport->requests();
        self::assertSame(90, $provision->timeoutSeconds);
        self::assertSame(30, $list->timeoutSeconds);
        self::assertSame(10, $list->connectTimeoutSeconds);
    }

    #[Test]
    public function itSendsAMinimalProvisionWithoutOptionalKeys(): void
    {
        $this->transport->queueData(ApiFixtures::provisionResult());

        $this->client->provisionPrivateLabel(self::minimalProvision());

        self::assertSame(
            '{"owner":{"email":"a@acme.test","firstName":"Ann","lastName":"Lee"},'
            . '"config":{"domain":"sign.acme.test","appName":"Acme"}}',
            $this->transport->lastRequest()->body,
        );
    }

    #[Test]
    public function itSendsTheOwnerBrandingAndPackageInCamelCase(): void
    {
        $this->transport->queueData(ApiFixtures::provisionResult());
        $request = new ProvisionRequest(
            'a@acme.test',
            'Ann',
            'Lee',
            'sign.acme.test',
            'Acme',
            [
                'featurePointsSystem' => false,
                'colorPrimary' => '#0055aa',
                'supportUrl' => null,
                'socialLinks' => [['name' => 'LinkedIn', 'url' => 'https://linkedin.test/acme', 'icon' => 'LinkedIn']],
            ],
            'pkg-1',
        );

        $this->client->provisionPrivateLabel($request);

        $body = self::bodyOf($this->transport->lastRequest());
        self::assertSame(
            [
                'owner' => ['email' => 'a@acme.test', 'firstName' => 'Ann', 'lastName' => 'Lee'],
                'config' => [
                    'domain' => 'sign.acme.test',
                    'appName' => 'Acme',
                    'socialLinks' => [
                        ['name' => 'LinkedIn', 'url' => 'https://linkedin.test/acme', 'icon' => 'LinkedIn'],
                    ],
                    'colorPrimary' => '#0055aa',
                    'featurePointsSystem' => false,
                ],
                'package' => ['packageId' => 'pkg-1'],
            ],
            $body,
        );
        self::assertStringNotContainsString('null', (string) $this->transport->lastRequest()->body);
    }

    #[Test]
    public function itAlwaysSendsThePackageDescriptionEvenWhenNull(): void
    {
        $this->transport->queueData(['id' => 'pkg-9']);
        $input = new PackageInput(
            'STARTER',
            'Starter',
            null,
            'USD',
            BillingCycle::MONTHLY,
            1900,
            [new PackageItem(ItemCode::SEATS, 10, 5, 900), new PackageItem(ItemCode::DOCUMENTS, 500)],
        );

        $packageId = $this->client->createPackage($input);

        self::assertSame('pkg-9', $packageId);
        self::assertSame(
            [
                'code' => 'STARTER',
                'name' => 'Starter',
                'description' => null,
                'currency' => 'USD',
                'billingCycle' => 'Monthly',
                'basePriceMinor' => 1900,
                'items' => [
                    [
                        'itemCode' => 'seats',
                        'includedQty' => 10,
                        'overageBundleSize' => 5,
                        'overageBundlePriceMinor' => 900,
                    ],
                    [
                        'itemCode' => 'documents',
                        'includedQty' => 500,
                        'overageBundleSize' => 0,
                        'overageBundlePriceMinor' => 0,
                    ],
                ],
            ],
            self::bodyOf($this->transport->lastRequest()),
        );
    }

    #[Test]
    public function itAlwaysSendsTheAddonDescriptionEvenWhenNull(): void
    {
        $this->transport->queueData(['id' => 'add-9']);
        $items = [new AddonItem(ItemCode::SEATS, 5)];
        $input = new AddonInput('SEATS5', '5 seats', null, 'USD', 500, BillingCycle::MONTHLY, $items);

        self::assertSame('add-9', $this->client->createAddon($input));
        self::assertSame(
            [
                'code' => 'SEATS5',
                'name' => '5 seats',
                'description' => null,
                'currency' => 'USD',
                'priceMinor' => 500,
                'billingCycle' => 'Monthly',
                'items' => [['itemCode' => 'seats', 'grantedQty' => 5]],
            ],
            self::bodyOf($this->transport->lastRequest()),
        );
    }

    #[Test]
    public function itSendsOnlyTheFieldsThatWereSetWithTheId(): void
    {
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(['message' => 'Success']);

        $this->client->updatePackage('pkg-1', (new PackageUpdate())->withDescription(null)->withActive(false));
        $this->client->updateAddon(
            'add-1',
            (new AddonUpdate())->withPriceMinor(700)->withItems([new AddonItem(ItemCode::SEATS, 3)]),
        );

        [$package, $addon] = $this->transport->requests();
        self::assertSame('POST', $package->method);
        self::assertSame(self::apiUrl('/billing/packages/update'), $package->url);
        self::assertSame(['id' => 'pkg-1', 'description' => null, 'isActive' => false], self::bodyOf($package));
        self::assertSame(self::apiUrl('/billing/addons/update'), $addon->url);
        self::assertSame(
            ['id' => 'add-1', 'priceMinor' => 700, 'items' => [['itemCode' => 'seats', 'grantedQty' => 3]]],
            self::bodyOf($addon),
        );
    }

    #[Test]
    public function itReadsEveryPageOfACatalogue(): void
    {
        $first = ['packages' => [ApiFixtures::packageSummary()], 'total' => 2, 'page' => 1, 'pageSize' => 100];
        $second = ['packages' => [['id' => 'pkg-2'] + ApiFixtures::packageSummary()], 'total' => 2, 'page' => 2];
        $this->transport->queueData($first);
        $this->transport->queueData($second + ['pageSize' => 100]);

        $packages = $this->client->listPackages();

        self::assertSame(['pkg-1', 'pkg-2'], array_map(static fn ($package) => $package->packageId, $packages));
        $pages = array_map(static fn ($request) => self::bodyOf($request)['page'], $this->transport->requests());
        self::assertSame([1, 2], $pages);
    }

    #[Test]
    public function itStopsReadingACatalogueAtAnEmptyPage(): void
    {
        $this->transport->queueData(['packages' => [], 'total' => 5, 'page' => 1, 'pageSize' => 100]);

        self::assertSame([], $this->client->listPackages());
        self::assertCount(1, $this->transport->requests());
    }

    #[Test]
    public function itEncodesEveryPathSegment(): void
    {
        $this->transport->queueData(['status' => 'removed']);

        $this->client->removeUser('tenant/one', 'user one?x=1#frag');

        self::assertSame(
            self::apiUrl('/private-labels/tenant%2Fone/users/user%20one%3Fx%3D1%23frag'),
            $this->transport->lastRequest()->url,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSegments(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
    }

    #[Test]
    #[DataProvider('unusableSegments')]
    public function itRefusesAPathSegmentThatWouldChangeTheRoute(string $userId): void
    {
        try {
            $this->client->removeUser(self::T, $userId);
            self::fail('An unusable path segment was sent.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->transport->requests());
        }
    }

    #[Test]
    public function itRefusesAMalformedUsagePeriodBeforeSending(): void
    {
        try {
            $this->client->getUsage('2026-13');
            self::fail('A malformed period was sent.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->transport->requests());
        }
    }

    #[Test]
    public function itRefusesBlankRequiredFieldsBeforeSending(): void
    {
        $calls = [
            fn () => $this->client->addUser(self::T, ' ', 'Bo', 'Chan'),
            fn () => $this->client->assignPackage(self::T, ''),
            fn () => $this->client->attachAddon(self::T, '', 1),
            fn () => $this->client->getPackage(' '),
            fn () => $this->client->updatePackage('', new PackageUpdate()),
            fn () => $this->client->getAddon(''),
            fn () => $this->client->updateAddon(' ', new AddonUpdate()),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('A blank required field was sent.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->transport->requests());
            }
        }
    }

    private static function minimalProvision(): ProvisionRequest
    {
        return new ProvisionRequest('a@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme');
    }
}
