<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Whmcs\ClientFactory;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Provisioning\ProvisionService;
use SignNet\Whmcs\Support\Settings;
use WHMCS\Database\Capsule;

final class ProvisionServiceTest extends ModuleTestCase
{
    private const PACKAGE_OF_TENANT = R::LABEL . '/package';
    private const ATTACH_CALL = 'POST ' . self::PACKAGE_OF_TENANT . '/addons';
    private const OLD_ADDONS = [
        ['addonId' => 'add-1', 'code' => 'SEATS5', 'quantity' => 2, 'subscriptionAddonId' => 'sa-1'],
        ['addonId' => 'add-7', 'code' => 'EXTRA', 'quantity' => 1, 'subscriptionAddonId' => 'sa-7'],
    ];

    private ServiceRepository $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new ServiceRepository();
        $this->insertService();
    }

    #[Test]
    public function itCreatesThePortalWithItsPackageAndShowsItOnTheService(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        self::assertSame([
            ...self::FIRST_CALLS,
            R::QUOTA_CALL,
            R::packageCall(),
            R::PROVISION_CALL,
        ], $this->calls(), 'The provisioning answer says the package was assigned, so it is not read back.');
        $body = $this->bodySentTo(R::PROVISION_CALL);
        self::assertSame(['email' => 'ada@acme.test', 'firstName' => 'Ada', 'lastName' => 'Lovelace'], $body['owner']);
        self::assertSame(['domain' => 'sign.acme.test', 'appName' => 'Acme Sign'], $body['config']);
        self::assertSame(['packageId' => 'pkg-1'], $body['package']);

        $link = $this->link();
        self::assertSame(R::TENANT_ID, $link->tenantId);
        self::assertSame(ServiceLink::STATE_ACTIVE, $link->state);
        self::assertSame(ServiceLink::ATTEMPT_NONE, $link->attemptState);
        self::assertSame(ServiceLink::PACKAGE_ASSIGNED, $link->packageState);
        self::assertTrue($link->domainSetup['attached'] ?? false);
        $service = Rows::first(Capsule::table('tblhosting')->where('id', self::SERVICE_ID));
        self::assertNotNull($service);
        self::assertSame([R::TENANT_ID, 'sign.acme.test'], [$service['username'], $service['domain']]);
        self::assertContains(
            'Sign.net: portal sign.acme.test created for service #101.',
            WhmcsFake::activityMessages(),
        );
        self::assertNoSecretsLogged();
    }

    #[Test]
    public function itGivesNewPortalsTheAddonsBrandingDefaults(): void
    {
        Capsule::table('tbladdonmodules')->insert([
            ['module' => 'signnet_reseller', 'setting' => 'color_primary', 'value' => '#112233'],
            ['module' => 'signnet_reseller', 'setting' => 'feature_stamps', 'value' => 'off'],
        ]);
        $this->queueSuccessfulCreate();

        $this->service()->create($this->params());

        $config = $this->bodySentTo(R::PROVISION_CALL)['config'];
        self::assertIsArray($config);
        self::assertSame('#112233', $config['colorPrimary']);
        self::assertFalse($config['featureStamps']);
    }

    #[Test]
    public function itHoldsAnOrderThatGoesPastTheAllowanceWithoutCreatingAnything(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota(['seats' => 4]));
        $this->transport->queueData(R::package('pkg-1', ['seats' => 10]));

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $result);
        self::assertStringContainsString('seats: 10 needed, 4 left (6 over)', $result);
        self::assertNotContains(R::PROVISION_CALL, $this->calls());
        $link = $this->link();
        self::assertFalse($link->isLinked());
        self::assertSame(ServiceLink::PACKAGE_HELD, $link->packageState);
        self::assertSame(['seats: 10 needed, 4 left (6 over)'], $link->heldWarnings);
        self::assertSame('sign.acme.test', $link->hostname);
    }

    #[Test]
    public function anApprovedOrderGoesPastTheAllowanceOnceByConfirmingIt(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, approvedAt: time()));
        $this->queueToken();
        $this->transport->queueData(R::provisioned(R::confirmationRequired('k-1')));
        $this->transport->queueData(R::assignment(null));
        $this->transport->queueData(R::confirmationRequired('k-2'));
        $this->transport->queueData(R::assigned());

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        $assignments = $this->requestsTo('POST ' . self::PACKAGE_OF_TENANT);
        self::assertCount(2, $assignments);
        self::assertSame(['packageId' => 'pkg-1'], self::bodyOf($assignments[0]));
        self::assertSame(['packageId' => 'pkg-1', 'confirmationKey' => 'k-2'], self::bodyOf($assignments[1]));
        $link = $this->link();
        self::assertSame(ServiceLink::PACKAGE_ASSIGNED, $link->packageState);
        self::assertNull($link->approvedAt, 'An approval covers one run.');
    }

    #[Test]
    public function itConfirmsOverTheAllowanceWhenTheProductSaysSo(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::provisioned(R::assigned()));

        $result = $this->service()->create($this->params(['configoption2' => 'auto']));

        self::assertSame('success', $result);
        self::assertNotContains(R::QUOTA_CALL, $this->calls());
    }

    #[Test]
    public function itAttachesTheAddonsTheCustomerOrdered(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::addonList('add-1', 'SEATS5'));
        $this->transport->queueData(R::addon('add-1', 'SEATS5', ['seats' => 5]));
        $this->transport->queueData(R::provisioned(R::assigned()));
        $this->transport->queueData(R::attached());

        $result = $this->service()->create($this->params(['configoptions' => ['addon_SEATS5' => 2]]));

        self::assertSame('success', $result);
        self::assertSame([
            ...self::FIRST_CALLS,
            R::QUOTA_CALL,
            R::packageCall(),
            R::ADDONS_CALL,
            R::addonCall(),
            R::PROVISION_CALL,
            'POST ' . self::PACKAGE_OF_TENANT . '/addons',
        ], $this->calls(), 'One add-on list serves the allowance check and the attachment.');
        self::assertSame(['addonId' => 'add-1', 'quantity' => 2], self::bodyOf($this->transport->lastRequest()));
        self::assertSame(ServiceLink::PACKAGE_ASSIGNED, $this->link()->packageState);
    }

    #[Test]
    public function aNewPortalWithNoAddonsOrderedReadsNoAddons(): void
    {
        $this->queueSuccessfulCreate();

        $result = $this->service()->create($this->params(['configoptions' => ['addon_SEATS5' => 0]]));

        self::assertSame('success', $result);
        self::assertSame([
            ...self::FIRST_CALLS,
            R::QUOTA_CALL,
            R::packageCall(),
            R::PROVISION_CALL,
        ], $this->calls());
    }

    #[Test]
    public function aNewPortalsAddonPastTheAllowanceHoldsTheOrder(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::addonList('add-1', 'SEATS5'));
        $this->transport->queueData(R::addon('add-1', 'SEATS5', ['seats' => 5]));
        $this->transport->queueData(R::provisioned(R::assigned()));
        $this->transport->queueData(R::confirmationRequired('k-1'));

        $result = $this->service()->create($this->params(['configoptions' => ['addon_SEATS5' => 2]]));

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $result);
        self::assertStringContainsString('seats: 10 needed, 4 left (6 over)', $result);
        $link = $this->link();
        self::assertTrue($link->isLinked());
        self::assertSame(ServiceLink::PACKAGE_HELD, $link->packageState);
    }

    #[Test]
    public function itCountsOrderedAddonsInTheAllowanceCheck(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota(['seats' => 15]));
        $this->transport->queueData(R::package('pkg-1', ['seats' => 10]));
        $this->transport->queueData(R::addonList('add-1', 'SEATS5'));
        $this->transport->queueData(R::addon('add-1', 'SEATS5', ['seats' => 5]));

        $result = $this->service()->create($this->params(['configoptions' => ['addon_SEATS5' => 2]]));

        self::assertStringContainsString('seats: 20 needed, 15 left (5 over)', $result);
    }

    #[Test]
    public function itAdoptsThePortalAnUnansweredCallCreated(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(new TransportException('Operation timed out', true));
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        self::assertSame(R::TENANT_ID, $this->link()->tenantId);
        self::assertSame(ServiceLink::ATTEMPT_NONE, $this->link()->attemptState);
        self::assertContains(
            'Sign.net: service #101 linked to the portal sign.acme.test that its earlier, unanswered '
            . 'provisioning created.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function aLostAnswerLeavesTheAttemptInDoubtAndTheNextRunFindsThePortalFirst(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(new TransportException('Operation timed out', true));
        $this->transport->queueData(R::labels());

        $first = $this->service()->create($this->params());

        self::assertStringContainsString('Running Create again is safe', $first);
        self::assertSame(ServiceLink::ATTEMPT_UNKNOWN, $this->link()->attemptState);

        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::assignment('pkg-1'));

        $second = $this->service()->create($this->params());

        self::assertSame('success', $second);
        self::assertSame(1, count($this->requestsTo(R::PROVISION_CALL)), 'Provisioned only once.');
    }

    #[Test]
    public function aServerErrorWithNoPortalListedIsNotInDoubt(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueJson(500, ['status' => 'Fail', 'error' => ['code' => 'INTERNAL_SERVER_ERROR']]);
        $this->transport->queueData(R::labels());

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith('Sign.net failed while creating the portal, and no portal was made.', $result);
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
    }

    #[Test]
    public function aRequestThatNeverLeftIsNotInDoubt(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(
            new TransportException('Could not resolve host', false),
            new TransportException('Could not resolve host', false),
        );

        $result = $this->service()->create($this->params());

        self::assertStringContainsString('Could not reach Sign.net', $result);
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
        self::assertNotContains('GET ' . R::LABELS, $this->calls());
    }

    #[Test]
    public function aTakenHostnameIsReportedWithoutAnEarlierAttemptToExplainIt(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'host_taken');

        $result = $this->service()->create($this->params());

        self::assertStringContainsString('sign.acme.test is already in use on Sign.net', $result);
        self::assertNotContains('GET ' . R::LABELS, $this->calls(), 'No evidence, so no adoption.');
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
    }

    #[Test]
    public function aRefusedProvisioningNamesTheDetailsToCheck(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'invalid_request');

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith('Sign.net refused the portal\'s details', $result);
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
    }

    #[Test]
    public function aTakenHostnameIsAdoptedWhenThisServicesOwnAttemptExplainsIt(): void
    {
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            hostname: 'sign.acme.test',
            attemptState: ServiceLink::ATTEMPT_UNKNOWN,
        ));
        $this->queueToken();
        $this->transport->queueData(R::labels());
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'host_taken');
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        self::assertSame(R::TENANT_ID, $this->link()->tenantId);
    }

    #[Test]
    public function onlyATakenHostnameLeadsToLookingForThePortal(): void
    {
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            hostname: 'sign.acme.test',
            attemptState: ServiceLink::ATTEMPT_UNKNOWN,
        ));
        $this->queueToken();
        $this->transport->queueData(R::labels());
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'seat_quota_reached');

        $result = $this->service()->create($this->params());

        self::assertStringNotContainsString('already in use', $result);
        self::assertCount(1, $this->requestsTo('GET ' . R::LABELS), 'Only the look before the attempt.');
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
    }

    #[Test]
    public function itNeverAdoptsAPortalAnotherServiceHolds(): void
    {
        $this->links->save(new ServiceLink(202, $this->serverId, R::TENANT_ID, 'other.acme.test'));
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            hostname: 'sign.acme.test',
            attemptState: ServiceLink::ATTEMPT_UNKNOWN,
        ));
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'host_taken');
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));

        $result = $this->service()->create($this->params());

        self::assertStringContainsString('already in use', $result);
        self::assertFalse($this->link()->isLinked());
    }

    #[Test]
    public function itRefusesAHostnameAnotherServiceUses(): void
    {
        $this->links->save(new ServiceLink(202, $this->serverId, R::TENANT_ID, 'sign.acme.test'));

        $result = $this->service()->create($this->params());

        self::assertSame('Service #202 already uses sign.acme.test.', $result);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function itExplainsThatADeletedPortalsHostnameCannotBeReused(): void
    {
        $this->links->save(new ServiceLink(
            202,
            $this->serverId,
            R::TENANT_ID,
            'sign.acme.test',
            state: ServiceLink::STATE_TERMINATED,
        ));

        $result = $this->service()->create($this->params());

        self::assertSame(
            'Service #202 used sign.acme.test for a portal since deleted, and a hostname can never be reused.',
            $result,
        );
    }

    #[Test]
    public function runningCreateAgainOnALinkedServiceOnlyChecksItsPlan(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        self::assertSame([...self::FIRST_CALLS, 'GET ' . self::PACKAGE_OF_TENANT], $this->calls());
    }

    #[Test]
    public function aPackageSignNetDidNotAssignIsReportedAndRetriedByTheNextRun(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(['outcome' => 'Failed', 'error' => 'NO_SUBSCRIPTION']));

        $result = $this->service()->create($this->params());

        self::assertStringContainsString('did not assign the package (NO_SUBSCRIPTION)', $result);
        self::assertTrue($this->link()->isLinked());
        self::assertSame(ServiceLink::PACKAGE_FAILED, $this->link()->packageState);

        $this->transport->queueData(R::assignment(null));
        $this->transport->queueData(R::assigned());

        self::assertSame('success', $this->service()->create($this->params()));
    }

    #[Test]
    public function itAsksForAPackageBeforeCallingSignNet(): void
    {
        $result = $this->service()->create($this->params(['configoption1' => '']));

        self::assertSame('Choose a Sign.net package in the product\'s Module Settings.', $result);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function itReportsAnInvalidPortalAddressBeforeCallingSignNet(): void
    {
        $result = $this->service()->create($this->params(['customfields' => ['Portal address' => 'not a host']]));

        self::assertStringContainsString('is not a valid portal address', $result);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function changingPackageSwapsItAndReattachesTheManagedAddons(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::addonList('add-1', 'SEATS5'));
        $this->transport->queueData(R::assignment('pkg-1', [
            ['addonId' => 'add-1', 'code' => 'SEATS5', 'quantity' => 2, 'subscriptionAddonId' => 'sa-1'],
        ]));
        $this->transport->queueData(R::package('pkg-2'));
        $this->transport->queueData(['outcome' => 'Unassigned']);
        $this->transport->queueData(R::assigned('asg-2'));
        $this->transport->queueData(R::attached());

        $result = $this->service()->applyPlan($this->params([
            'configoption1' => 'pkg-2',
            'configoptions' => ['addon_SEATS5' => 2],
        ]));

        self::assertSame('success', $result);
        self::assertSame([
            ...self::FIRST_CALLS,
            R::ADDONS_CALL,
            'GET ' . self::PACKAGE_OF_TENANT,
            R::packageCall(),
            'DELETE ' . self::PACKAGE_OF_TENANT,
            'POST ' . self::PACKAGE_OF_TENANT,
            'POST ' . self::PACKAGE_OF_TENANT . '/addons',
        ], $this->calls());
        self::assertNotEmpty(array_filter(
            WhmcsFake::activityMessages(),
            static fn (string $message): bool => str_contains($message, 'Package PKG-1 is swapped'),
        ));
    }

    #[Test]
    public function aSwapThatCannotAssignTheNewPackagePutsTheOldOneBack(): void
    {
        $this->queueSwapThatFails();
        $this->transport->queueData(R::assigned('asg-3'));

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertStringStartsWith('The new package could not be assigned, so PKG-1 was put back. ', $result);
        self::assertMatchesRegularExpression(
            '/archived\. \(request [^)]+\)$/',
            $result,
            'The id of the failed call ends the message.',
        );
        self::assertSame(['packageId' => 'pkg-1'], self::bodyOf($this->transport->lastRequest()));
        self::assertSame(ServiceLink::PACKAGE_FAILED, $this->link()->packageState);
    }

    #[Test]
    public function aSwapToAnArchivedPackageChangesNothing(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1', self::OLD_ADDONS));
        $this->transport->queueData(R::package('pkg-2', active: false));

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertSame('The package PKG-2 is archived in Sign.net.', $result);
        self::assertSame(
            [...self::FIRST_CALLS, 'GET ' . self::PACKAGE_OF_TENANT, R::packageCall()],
            $this->calls(),
            'The portal keeps its package, its add-ons and the credit it carries.',
        );
        self::assertSame([], array_filter(
            WhmcsFake::activityMessages(),
            static fn (string $message): bool => str_contains($message, 'is swapped'),
        ));
        self::assertSame(ServiceLink::PACKAGE_FAILED, $this->link()->packageState);
    }

    #[Test]
    public function aFailedSwapPutsBackTheAddonsThePortalHeldAsTheyWere(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::addonList('add-1', 'SEATS5'));
        $this->transport->queueData(R::assignment('pkg-1', self::OLD_ADDONS));
        $this->transport->queueData(R::package('pkg-2'));
        $this->transport->queueData(['outcome' => 'Unassigned']);
        $this->transport->queueError(400, 'package_inactive');
        $this->transport->queueData(R::assigned('asg-3'));
        $this->transport->queueData(R::attached('sa-11'));
        $this->transport->queueData(R::attached('sa-12'));

        $result = $this->service()->applyPlan($this->params([
            'configoption1' => 'pkg-2',
            'configoptions' => ['addon_SEATS5' => 3],
        ]));

        self::assertStringStartsWith(
            'The new package could not be assigned, so PKG-1 was put back with its add-ons. ',
            $result,
        );
        self::assertSame(
            [['addonId' => 'add-1', 'quantity' => 2], ['addonId' => 'add-7', 'quantity' => 1]],
            array_map(self::bodyOf(...), $this->requestsTo(self::ATTACH_CALL)),
            'Both come back as the portal held them: the one WHMCS sells, and the one attached in Sign.net.',
        );
        self::assertSame(ServiceLink::PACKAGE_FAILED, $this->link()->packageState);
    }

    #[Test]
    public function aSwapHeldForApprovalPutsBackThePackageAndItsAddons(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1', [self::OLD_ADDONS[0]]));
        $this->transport->queueData(R::package('pkg-2'));
        $this->transport->queueData(['outcome' => 'Unassigned']);
        $this->transport->queueData(R::confirmationRequired());
        $this->transport->queueData(R::assigned('asg-3'));
        $this->transport->queueData(R::attached('sa-11'));

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $result);
        self::assertSame(['addonId' => 'add-1', 'quantity' => 2], $this->bodySentTo(self::ATTACH_CALL));
        self::assertContains(
            'Sign.net: The new package could not be assigned, so PKG-1 was put back with its add-ons. (service #101)',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function anAddonSignNetRefusesToAttachAgainIsNamedAndTheRestStillComeBack(): void
    {
        $this->queueSwapThatFails(self::OLD_ADDONS);
        $this->transport->queueData(R::assigned('asg-3'));
        $this->transport->queueError(400, 'addon_inactive');
        $this->transport->queueData(R::attached('sa-12'));

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertStringStartsWith(
            'The new package could not be assigned, so PKG-1 was put back, but attaching SEATS5 (x2) to it again '
                . 'failed: An add-on this product sells is archived in Sign.net. (request ',
            $result,
        );
        self::assertCount(2, $this->requestsTo(self::ATTACH_CALL));
    }

    #[Test]
    public function aRateLimitStopsPuttingAddonsBack(): void
    {
        $this->queueSwapThatFails(self::OLD_ADDONS);
        $this->transport->queueData(R::assigned('asg-3'));
        $this->transport->queueJson(
            429,
            ['error' => 'Too many requests, please try again later.'],
            ['Retry-After' => '42'],
        );

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertStringStartsWith(
            'The new package could not be assigned, so PKG-1 was put back, but attaching SEATS5 (x2), EXTRA (x1) to '
                . 'it again failed: Sign.net is rate limiting these requests; try again in 42 seconds.',
            $result,
        );
        self::assertCount(1, $this->requestsTo(self::ATTACH_CALL), 'Sign.net would refuse the rest too.');
    }

    #[Test]
    public function noAddonIsAttachedWhenTheOldPackageCannotBePutBack(): void
    {
        $this->queueSwapThatFails(self::OLD_ADDONS);
        $this->transport->queueError(400, 'already_assigned');

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertStringStartsWith(
            'The new package could not be assigned, and putting PKG-1 back failed too: ',
            $result,
        );
        self::assertSame([], $this->requestsTo(self::ATTACH_CALL));
    }

    #[Test]
    public function changingAnAddonQuantityReplacesItsAttachment(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::addonList('add-1', 'SEATS5'));
        $this->transport->queueData(R::assignment('pkg-1', [
            ['addonId' => 'add-1', 'code' => 'SEATS5', 'quantity' => 2, 'subscriptionAddonId' => 'sa-1'],
        ]));
        $this->transport->queueData(['outcome' => 'Removed']);
        $this->transport->queueData(R::attached('sa-2'));

        $result = $this->service()->applyPlan($this->params(['configoptions' => ['addon_SEATS5' => 3]]));

        self::assertSame('success', $result);
        self::assertSame([
            ...self::FIRST_CALLS,
            R::ADDONS_CALL,
            'GET ' . self::PACKAGE_OF_TENANT,
            'DELETE ' . self::PACKAGE_OF_TENANT . '/addons/sa-1',
            'POST ' . self::PACKAGE_OF_TENANT . '/addons',
        ], $this->calls());
    }

    #[Test]
    public function anAddonAttachedOnlyInSignNetIsLeftAlone(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1', [
            ['addonId' => 'add-7', 'code' => 'EXTRA', 'quantity' => 1, 'subscriptionAddonId' => 'sa-7'],
        ]));

        self::assertSame('success', $this->service()->applyPlan($this->params()));
        self::assertSame([...self::FIRST_CALLS, 'GET ' . self::PACKAGE_OF_TENANT], $this->calls());
    }

    /**
     * A linked portal on pkg-1 holding $addons, whose swap to pkg-2 Sign.net refuses after the old
     * package has been unassigned: pkg-2 was archived between the check and the assignment.
     *
     * @param list<array{addonId: string, code: string, quantity: int, subscriptionAddonId: string}> $addons
     */
    private function queueSwapThatFails(array $addons = []): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1', $addons));
        $this->transport->queueData(R::package('pkg-2'));
        $this->transport->queueData(['outcome' => 'Unassigned']);
        $this->transport->queueError(400, 'package_inactive');
    }

    private function queueSuccessfulCreate(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));
    }

    private function service(): ProvisionService
    {
        return new ProvisionService(
            ClientFactory::fromParams($this->params()),
            $this->links,
            Settings::load(),
        );
    }

    private function link(): ServiceLink
    {
        $link = $this->links->find(self::SERVICE_ID);
        self::assertNotNull($link);

        return $link;
    }
}
