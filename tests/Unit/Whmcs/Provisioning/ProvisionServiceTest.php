<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Http\Transport;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Unit\ResellerApi\Fake\FakeTransport;
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

    /** A product that confirms past the allowance, so the provisioning call is the run's first. */
    private const AUTO_CONFIRM = ['configoption2' => 'auto'];

    private const TAKEN = 'sign.acme.test is already in use on Sign.net, and a hostname can never be reused. Ask the '
        . 'customer for another portal address, or use "Link existing portal" if the portal is already yours.';

    /** The client's email address once changed since an earlier call gave ada@acme.test. */
    private const NEW_EMAIL = ['clientsdetails' => ['email' => 'ada.new@acme.test']];

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
    public function anApprovedChangeThatFailsUsesTheApprovalUp(): void
    {
        $this->saveHeldPortal();
        $this->service()->approveOverAllowance(self::SERVICE_ID);
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1'));
        $this->transport->queueData(R::package('pkg-2', active: false));

        $failed = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertSame('The package PKG-2 is archived in Sign.net.', $failed);
        self::assertNull($this->link()->approvedAt, 'An approval covers one run, whatever its outcome.');

        $this->transport->queueData(R::assignment('pkg-1'));
        $this->transport->queueData(R::package('pkg-3'));
        $this->transport->queueData(['outcome' => 'Unassigned']);
        $this->transport->queueData(R::confirmationRequired('k-9'));
        $this->transport->queueData(R::assigned('asg-3'));

        $later = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-3']));

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $later, 'A later change is held again.');
        self::assertSame(
            [['packageId' => 'pkg-3'], ['packageId' => 'pkg-1']],
            array_map(self::bodyOf(...), $this->requestsTo('POST ' . self::PACKAGE_OF_TENANT)),
            'Nothing was confirmed past the allowance, and the old package was put back.',
        );
    }

    #[Test]
    public function anApprovedChangeHeldAgainUsesTheApprovalUp(): void
    {
        $this->saveHeldPortal();
        $this->service()->approveOverAllowance(self::SERVICE_ID);
        $this->queueToken();
        $this->transport->queueData(R::assignment('pkg-1'));
        $this->transport->queueData(R::package('pkg-2'));
        $this->transport->queueData(['outcome' => 'Unassigned']);
        $this->transport->queueData(R::confirmationRequired('k-1'));
        $this->transport->queueData(R::confirmationRequired('k-2'));
        $this->transport->queueData(R::assigned('asg-3'));

        $result = $this->service()->applyPlan($this->params(['configoption1' => 'pkg-2']));

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $result);
        $link = $this->link();
        self::assertSame(ServiceLink::PACKAGE_HELD, $link->packageState);
        self::assertNull($link->approvedAt, 'Sign.net\'s numbers moved since the approval, so it needs a new one.');
    }

    #[Test]
    public function anApprovedOrderSignNetRefusesIsCheckedAgainstTheAllowanceNextTime(): void
    {
        $this->links->save(new ServiceLink(self::SERVICE_ID, $this->serverId, approvedAt: time()));
        $this->queueToken();
        $this->transport->queueError(400, 'invalid_request');

        $refused = $this->service()->create($this->params());

        self::assertStringStartsWith('Sign.net refused the portal\'s details', $refused);
        self::assertNull($this->link()->approvedAt);

        $this->transport->queueData(R::quota(['seats' => 4]));
        $this->transport->queueData(R::package('pkg-1', ['seats' => 10]));

        $next = $this->service()->create($this->params());

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $next);
        self::assertCount(1, $this->requestsTo(R::PROVISION_CALL));
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
        $this->transport->queueData(R::labelCreatedAt(time()));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        $link = $this->link();
        self::assertSame(R::TENANT_ID, $link->tenantId);
        self::assertSame(ServiceLink::STATE_ACTIVE, $link->state);
        self::assertSame(ServiceLink::ATTEMPT_NONE, $link->attemptState);
        self::assertTrue($link->domainSetup['attached'] ?? false, 'Its domain setup is recorded, as for a new one.');
        self::assertContains(
            'Sign.net: service #101 linked to the portal sign.acme.test. Its provisioning call went unanswered, and '
            . 'this portal fits that call: it has the address the call asked for, was created after the call was '
            . 'sent (allowing ten minutes for clock differences), and is owned by ada@acme.test, the email address '
            . 'the call gave.',
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

        self::assertStringStartsWith(
            'Sign.net did not confirm that the portal was created. Running Create again is safe: it looks for the '
            . 'portal first. Do not change the portal address before it has. Details: Could not reach Sign.net: ',
            $first,
        );
        self::assertSame(ServiceLink::ATTEMPT_UNKNOWN, $this->link()->attemptState);

        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time()));
        $this->transport->queueData(R::assignment('pkg-1'));

        $second = $this->service()->create($this->params());

        self::assertSame('success', $second);
        self::assertSame(1, count($this->requestsTo(R::PROVISION_CALL)), 'Provisioned only once.');
    }

    #[Test]
    public function aPortalThatWasThereBeforeTheCallIsNotAdoptedAfterAnUnreadableAnswer(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(self::gatewayPage());
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time() - 3600));

        $result = $this->service()->create($this->params());

        self::assertSame(self::TAKEN, $result);
        $link = $this->link();
        self::assertFalse($link->isLinked());
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $link->attemptState);
        self::assertNotContains('GET ' . self::PACKAGE_OF_TENANT, $this->calls(), 'The portal is left as it is.');
    }

    #[Test]
    public function aPortalCreatedDuringTheCallIsAdoptedAfterAnUnreadableAnswer(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(self::gatewayPage());
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), 'Ada@ACME.test'));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result, 'The owner\'s email address matches whatever its case.');
        self::assertSame(R::TENANT_ID, $this->link()->tenantId);
        self::assertCount(1, $this->requestsTo('GET ' . R::LABEL), 'The portal is read to check that it fits.');
        self::assertSame(0, $this->transport->pendingCount());
    }

    #[Test]
    public function aPortalWithNoOwnerYetRightAfterTheCallIsLinkedByTheNextRunIfItStillHasNone(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(self::gatewayPage());
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), null));

        $first = $this->service()->create($this->params());

        self::assertSame(
            'Sign.net did not confirm that the portal was created. A portal on sign.acme.test may be the one this '
            . 'call made, but it has no owner yet, so it cannot be told apart from another order\'s. Run Create '
            . 'again in a minute: it links the portal if it still has no owner then, or if its owner has the email '
            . 'address this call gave.',
            $first,
        );
        self::assertFalse($this->link()->isLinked());
        self::assertSame(ServiceLink::ATTEMPT_UNKNOWN, $this->link()->attemptState);
        self::assertNotContains('GET ' . self::PACKAGE_OF_TENANT, $this->calls(), 'The portal is left as it is.');

        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), null));
        $this->transport->queueData(R::assignment('pkg-1'));

        self::assertSame('success', $this->service()->create($this->params()));
        self::assertSame(R::TENANT_ID, $this->link()->tenantId);
        self::assertCount(1, $this->requestsTo(R::PROVISION_CALL));
        self::assertContains(
            'Sign.net: service #101 linked to the portal sign.acme.test. Its provisioning call went unanswered, and '
            . 'this portal fits that call: it has the address the call asked for, was created after the call was '
            . 'sent (allowing ten minutes for clock differences), and has no owner yet.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function aPortalWithNoOwnerYetRightAfterTheCallIsNotTakenOnceAnotherOwnerAppears(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueJson(500, ['status' => 'Fail', 'error' => ['code' => 'INTERNAL_SERVER_ERROR']]);
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), null));
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), 'someone@other.test'));

        $first = $this->service()->create($this->params());
        $second = $this->service()->create($this->params());

        self::assertStringStartsWith('Sign.net did not confirm that the portal was created. A portal on', $first);
        self::assertSame(self::TAKEN, $second, 'Another order made it, at the same moment.');
        self::assertFalse($this->link()->isLinked());
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
        self::assertSame(0, $this->transport->pendingCount());
    }

    #[Test]
    public function aTakenHostnameWithAPortalThatHasNoOwnerYetWaitsForTheNextRun(): void
    {
        $this->saveUnansweredAttempt(time() - 3600);
        $this->queueToken();
        $this->transport->queueData(R::labels());
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'host_taken');
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), null));

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith('Sign.net did not confirm that the portal was created. A portal on', $result);
        self::assertFalse($this->link()->isLinked());
        self::assertSame(ServiceLink::ATTEMPT_UNKNOWN, $this->link()->attemptState);
    }

    #[Test]
    public function aPortalOnAnAddressAnotherServiceIsStillProvisioningIsNotTaken(): void
    {
        $this->insertService(102, 'Pending', 501);
        $this->provisionWhileAnotherServiceIsSending(new ServiceLink(
            102,
            $this->serverId,
            hostname: 'sign.acme.test',
            ownerEmail: 'grace@other.test',
            attemptState: ServiceLink::ATTEMPT_UNKNOWN,
            attemptAt: time(),
        ));
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueJson(500, ['status' => 'Fail', 'error' => ['code' => 'INTERNAL_SERVER_ERROR']]);
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), 'ada@acme.test'));

        $result = $this->service()->create($this->params());

        self::assertSame(self::TAKEN, $result, 'Service 102\'s call may have made it.');
        self::assertFalse($this->link()->isLinked());
        self::assertNotContains('GET ' . self::PACKAGE_OF_TENANT, $this->calls(), 'The portal is left as it is.');
    }

    #[Test]
    public function aPortalSomeoneElseOwnsIsNotAdoptedAfterALostAnswer(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queue(new TransportException('Operation timed out', true));
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time(), 'someone@other.test'));

        $result = $this->service()->create($this->params());

        self::assertSame(self::TAKEN, $result);
        self::assertFalse($this->link()->isLinked());
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
    }

    #[Test]
    public function theNextRunAdoptsAPortalSignNetsClockPutsALittleBeforeTheCall(): void
    {
        $this->saveUnansweredAttempt(time());
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time() - 300));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        self::assertSame(R::TENANT_ID, $this->link()->tenantId);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL));
        self::assertSame(0, $this->transport->pendingCount());
    }

    #[Test]
    public function theNextRunAdoptsThePortalOwnedByTheEmailAddressTheCallGave(): void
    {
        $this->saveUnansweredAttempt(time() - 3600);
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time() - 3500, 'ada@acme.test'));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params(self::NEW_EMAIL));

        self::assertSame('success', $result, 'The client\'s email address changed after the call.');
        self::assertSame([R::TENANT_ID, 'ada@acme.test'], [$this->link()->tenantId, $this->link()->ownerEmail]);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL));
    }

    #[Test]
    public function theNextRunReportsAPortalMadeBeforeTheCallAsTakingTheHostname(): void
    {
        $this->saveUnansweredAttempt(time());
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time() - 900));

        $result = $this->service()->create($this->params());

        self::assertSame(self::TAKEN, $result);
        self::assertFalse($this->link()->isLinked());
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL), 'Sign.net would refuse the hostname anyway.');
    }

    #[Test]
    public function theNextRunLeavesTheAttemptInDoubtWhenItCannotLook(): void
    {
        $this->saveUnansweredAttempt(time() - 60);
        $this->queueToken();
        $this->transport->queueError(500, 'internal_server_error');

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith('Sign.net did not confirm that the portal was created.', $result);
        self::assertSame(ServiceLink::ATTEMPT_UNKNOWN, $this->link()->attemptState);
        self::assertSame($result, $this->link()->lastError);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL));
    }

    #[Test]
    public function aNewAddressAfterALostAnswerLinksThePortalTheCallMadeAndKeepsItsAddress(): void
    {
        $this->saveUnansweredAttempt(time() - 60, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'old.acme.test']));
        $this->transport->queueData(['primaryHost' => 'old.acme.test'] + R::labelCreatedAt(time()));

        $result = $this->service()->create($this->params());

        self::assertSame(
            'The portal address was not changed to sign.acme.test. The earlier provisioning call, whose answer never '
            . 'arrived, made a portal on old.acme.test, and this service now manages it; the plugin cannot move a '
            . 'portal to another address. Set the service\'s portal address back to old.acme.test, then run Create '
            . 'again to finish.',
            $result,
        );
        $link = $this->link();
        self::assertSame([R::TENANT_ID, 'old.acme.test'], [$link->tenantId, $link->hostname]);
        self::assertSame(ServiceLink::ATTEMPT_NONE, $link->attemptState);
        self::assertSame($result, $link->lastError);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL), 'No second portal.');
        $service = Rows::first(Capsule::table('tblhosting')->where('id', self::SERVICE_ID));
        self::assertSame('old.acme.test', $service['domain'] ?? null);

        $this->transport->queueData(R::assignment('pkg-1'));

        self::assertSame('success', $this->service()->create($this->params()), 'Create again finishes.');
        self::assertNull($this->link()->lastError);
    }

    #[Test]
    public function aNewAddressAfterALostAnswerLinksThePortalOwnedByTheEmailAddressTheCallGave(): void
    {
        $this->saveUnansweredAttempt(time() - 60, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'old.acme.test']));
        $this->transport->queueData(['primaryHost' => 'old.acme.test'] + R::labelCreatedAt(time(), 'ada@acme.test'));

        $result = $this->service()->create($this->params(self::NEW_EMAIL));

        self::assertStringStartsWith('The portal address was not changed to sign.acme.test.', $result);
        self::assertSame([R::TENANT_ID, 'old.acme.test'], [$this->link()->tenantId, $this->link()->hostname]);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL), 'No second portal.');
    }

    #[Test]
    public function aNewAddressAfterALostAnswerWaitsWhenTheOldOnesNewPortalHasAnotherOwner(): void
    {
        $this->saveUnansweredAttempt(time() - 3600, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'old.acme.test']));
        $this->transport->queueData(['primaryHost' => 'old.acme.test'] + R::labelCreatedAt(time() - 3500, 'x@y.test'));

        $result = $this->service()->create($this->params());

        self::assertSame(
            'Resolve the earlier provisioning call for old.acme.test before using sign.acme.test: its answer never '
            . 'arrived, and a portal created on old.acme.test since may be the one it made, though nothing confirms '
            . 'it. If that portal is this service\'s, link it with "Link existing portal". If it is not, set the '
            . 'portal address back to old.acme.test and run Create, which then reports old.acme.test as in use; '
            . 'sign.acme.test can be used after that.',
            $result,
        );
        $link = $this->link();
        self::assertSame(['old.acme.test', ServiceLink::ATTEMPT_UNKNOWN], [$link->hostname, $link->attemptState]);
        self::assertFalse($link->isLinked());
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL), 'The portal may be the call\'s.');
    }

    #[Test]
    public function aPortalThatIsNotTheCallsCanBeSetAsideToUseANewAddress(): void
    {
        $this->saveUnansweredAttempt(time() - 3600, 'old.acme.test');
        $this->queueToken();
        $theirs = ['primaryHost' => 'old.acme.test'] + R::labelCreatedAt(time() - 3500, 'x@y.test');
        $this->transport->queueData(R::labels([R::TENANT_ID => 'old.acme.test']));
        $this->transport->queueData($theirs);
        $this->transport->queueData(R::labels([R::TENANT_ID => 'old.acme.test']));
        $this->transport->queueData($theirs);
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        $waiting = $this->service()->create($this->params());
        $setAside = $this->service()->create($this->params(['customfields' => ['Portal address' => 'old.acme.test']]));
        $created = $this->service()->create($this->params());

        self::assertStringStartsWith('Resolve the earlier provisioning call for old.acme.test', $waiting);
        self::assertStringStartsWith('old.acme.test is already in use on Sign.net', $setAside);
        self::assertSame('success', $created);
        self::assertSame(['sign.acme.test', R::TENANT_ID], [$this->link()->hostname, $this->link()->tenantId]);
        self::assertCount(1, $this->requestsTo(R::PROVISION_CALL));
    }

    #[Test]
    public function aNewAddressAfterALostAnswerWaitsWhileTheCallMayStillMakeItsPortal(): void
    {
        $sentAt = time() - 60;
        $this->saveUnansweredAttempt($sentAt, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels());

        $result = $this->service()->create($this->params());

        self::assertSame(
            'Resolve the earlier provisioning call for old.acme.test before using sign.acme.test: its answer never '
            . 'arrived, and it was sent less than ten minutes ago, so it may still make a portal on old.acme.test. '
            . 'Run Create again after ' . gmdate('Y-m-d H:i', $sentAt + 600) . ' UTC to look once more.',
            $result,
        );
        $link = $this->link();
        self::assertSame(['old.acme.test', ServiceLink::ATTEMPT_UNKNOWN], [$link->hostname, $link->attemptState]);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL));
    }

    #[Test]
    public function aNewAddressAfterALostAnswerIsUsedWhenTheCallMadeNoPortal(): void
    {
        $this->saveUnansweredAttempt(time() - 3600, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels());
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result);
        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABELS], array_slice($this->calls(), 0, 3));
        self::assertSame('sign.acme.test', $this->link()->hostname);
        self::assertSame(
            'sign.acme.test',
            self::bodyOf($this->requestTo(R::PROVISION_CALL))['config']['domain'] ?? null,
        );
    }

    #[Test]
    public function aNewAddressAfterALostAnswerIsUsedWhenTheOldOnesPortalIsNotTheCalls(): void
    {
        $this->saveUnansweredAttempt(time() - 60, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'old.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time() - 3600));
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result, 'That portal took the old address before the call, so it made none.');
        self::assertSame('sign.acme.test', $this->link()->hostname);
    }

    #[Test]
    public function aNewAddressAfterALostAnswerLeavesNothingInDoubtOnceTheOldOneIsClear(): void
    {
        $this->saveUnansweredAttempt(time() - 3600, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueData(R::labels());
        $this->transport->queueData(R::quota(['seats' => 4]));
        $this->transport->queueData(R::package('pkg-1', ['seats' => 10]));

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith(ProvisionService::HELD_PREFIX, $result);
        $link = $this->link();
        self::assertSame('sign.acme.test', $link->hostname);
        self::assertSame([ServiceLink::ATTEMPT_NONE, null], [$link->attemptState, $link->attemptAt]);
    }

    #[Test]
    public function aNewAddressAfterALostAnswerWaitsUntilTheOldOneCanBeLookedAt(): void
    {
        $this->saveUnansweredAttempt(time() - 60, 'old.acme.test');
        $this->queueToken();
        $this->transport->queueError(500, 'internal_server_error');

        $result = $this->service()->create($this->params());

        self::assertStringStartsWith(
            'Resolve the earlier provisioning call for old.acme.test before using sign.acme.test: its answer never '
            . 'arrived, and looking for the portal it may have made on old.acme.test failed. Run Create again to '
            . 'look once more. Details: Sign.net could not complete the request (HTTP 500).',
            $result,
        );
        $link = $this->link();
        self::assertSame(['old.acme.test', ServiceLink::ATTEMPT_UNKNOWN], [$link->hostname, $link->attemptState]);
        self::assertSame([], $this->requestsTo(R::PROVISION_CALL));
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
    public function aTokenSignNetFailsToMintLeavesTheAttemptUnsentSoTheNextRunProvisions(): void
    {
        $this->transport->queueJson(500, ['error' => 'server_error']);

        $first = $this->service()->create($this->params(self::AUTO_CONFIRM));

        self::assertStringStartsWith('Could not reach Sign.net: Could not get an access token: ', $first);
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
        self::assertSame([R::TOKEN_CALL], $this->calls(), 'The provisioning call never left.');

        $this->queueToken();
        $this->transport->queueData(R::provisioned(R::assigned()));

        self::assertSame('success', $this->service()->create($this->params(self::AUTO_CONFIRM)));
        self::assertNotContains('GET ' . R::LABELS, $this->calls(), 'Nothing was sent, so nothing is looked for.');
        self::assertCount(1, $this->requestsTo(R::PROVISION_CALL));
    }

    #[Test]
    public function aSlugSignNetFailsToGiveLeavesTheAttemptUnsentSoTheNextRunProvisions(): void
    {
        $this->queueAccessToken();
        $this->transport->queueError(500, 'internal_server_error');

        $first = $this->service()->create($this->params(self::AUTO_CONFIRM));

        self::assertStringStartsWith(
            'Could not reach Sign.net: Could not learn the reseller console address: ',
            $first,
        );
        self::assertSame(ServiceLink::ATTEMPT_FAILED, $this->link()->attemptState);
        self::assertSame([R::TOKEN_CALL, R::DASHBOARD_CALL], $this->calls(), 'The provisioning call never left.');

        $this->transport->queueData(R::dashboard());
        $this->transport->queueData(R::provisioned(R::assigned()));

        self::assertSame('success', $this->service()->create($this->params(self::AUTO_CONFIRM)));
        self::assertNotContains('GET ' . R::LABELS, $this->calls(), 'Nothing was sent, so nothing is looked for.');
        self::assertCount(1, $this->requestsTo(R::PROVISION_CALL));
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
        $this->saveUnansweredAttempt(time() - 3600);
        $this->queueToken();
        $this->transport->queueData(R::labels());
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueError(400, 'host_taken');
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));
        $this->transport->queueData(R::labelCreatedAt(time() - 3000));
        $this->transport->queueData(R::assignment('pkg-1'));

        $result = $this->service()->create($this->params());

        self::assertSame('success', $result, 'The retry keeps the time of the call that went unanswered.');
        self::assertSame(R::TENANT_ID, $this->link()->tenantId);
        self::assertSame(0, $this->transport->pendingCount());
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
        $this->saveUnansweredAttempt(time() - 60);
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));

        $result = $this->service()->create($this->params());

        self::assertSame(self::TAKEN, $result);
        self::assertFalse($this->link()->isLinked());
        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABELS], $this->calls());
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

    /**
     * Service 101's portal on pkg-1, with a change of plan held over the allowance.
     */
    private function saveHeldPortal(): void
    {
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            R::TENANT_ID,
            'sign.acme.test',
            state: ServiceLink::STATE_ACTIVE,
            packageState: ServiceLink::PACKAGE_HELD,
        ));
    }

    /**
     * Service 101's earlier provisioning call for $hostname, sent at $attemptAt (Unix seconds), whose
     * answer never arrived.
     */
    private function saveUnansweredAttempt(int $attemptAt, string $hostname = 'sign.acme.test'): void
    {
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            hostname: $hostname,
            ownerEmail: 'ada@acme.test',
            attemptState: ServiceLink::ATTEMPT_UNKNOWN,
            attemptAt: $attemptAt,
        ));
    }

    /**
     * Saves $other just as service 101's provisioning call is sent: another service's call for the same
     * address, which went out at the same moment, after 101 checked that no service used the address.
     */
    private function provisionWhileAnotherServiceIsSending(ServiceLink $other): void
    {
        $links = $this->links;
        ClientFactory::useTestDoubles(new class ($this->transport, $links, $other) implements Transport {
            public function __construct(
                private readonly FakeTransport $inner,
                private readonly ServiceRepository $links,
                private readonly ServiceLink $other,
            ) {
            }

            public function send(Request $request): Response
            {
                if ($request->method === 'POST' && $request->target() === R::LABELS) {
                    $this->links->save($this->other);
                }

                return $this->inner->send($request);
            }
        }, $this->sleeper);
    }

    /**
     * A gateway's own error page in place of Sign.net's answer.
     */
    private static function gatewayPage(): Response
    {
        return new Response(502, ['Content-Type' => 'text/html'], '<html><body>Bad Gateway</body></html>');
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
