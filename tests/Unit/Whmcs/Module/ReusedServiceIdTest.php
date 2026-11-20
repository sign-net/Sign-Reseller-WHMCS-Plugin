<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Module;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Support\Html;
use WHMCS\Database\Capsule;

/**
 * A newer service that WHMCS gave the id of a deleted service whose portal is still live, as MySQL
 * 5.7 can after a restart: the newer service never takes that portal over.
 */
final class ReusedServiceIdTest extends ModuleTestCase
{
    private const OLD_TENANT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OLD_HOST = 'sign.first-client.test';
    private const NEW_ORDER_ID = 600;
    private const NEW_CLIENT_ID = 8;

    private const REFUSAL = 'The Sign.net portal sign.first-client.test (tenant aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa) was '
        . 'made for a WHMCS service since deleted, and WHMCS has given that service\'s id, #101, to this newer '
        . 'service. Nothing was done to the portal. To give this service a portal of its own, click "Unlink (keeps '
        . 'the portal)", then run Create. To manage sign.first-client.test again, unlink it here, then use "Link '
        . 'existing portal" on the service it belongs to.';
    private const CHECK_FAILED = 'We could not check your portal\'s address right now. Please try again later.';

    private ServiceRepository $links;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../../modules/servers/signnet/signnet.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new ServiceRepository();
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            self::OLD_TENANT_ID,
            self::OLD_HOST,
            'First Sign',
            state: ServiceLink::STATE_ACTIVE,
            packageState: ServiceLink::PACKAGE_ASSIGNED,
        ));
        Capsule::table('tblhosting')->where('id', self::SERVICE_ID)->delete();
        Capsule::table('tblhosting')->insert([
            'id' => self::SERVICE_ID,
            'userid' => self::NEW_CLIENT_ID,
            'orderid' => self::NEW_ORDER_ID,
            'packageid' => 3,
            'server' => $this->serverId,
            'domainstatus' => 'Pending',
        ]);
    }

    #[Test]
    public function everyActionLeavesThePortalOfTheDeletedServiceAlone(): void
    {
        $params = $this->newerServicesParams();

        $results = [
            'CreateAccount' => signnet_CreateAccount($params),
            'ChangePackage' => signnet_ChangePackage($params),
            'SuspendAccount' => signnet_SuspendAccount($params),
            'UnsuspendAccount' => signnet_UnsuspendAccount($params),
            'TerminateAccount' => signnet_TerminateAccount($params),
            'refresh' => signnet_refresh($params),
            'applyPlan' => signnet_applyPlan($params),
            'approveOverAllowance' => signnet_approveOverAllowance($params),
            'retryDomainAttach' => signnet_retryDomainAttach($params),
            'resendOwnerInvite' => signnet_resendOwnerInvite($params),
            'linkPortal' => signnet_linkPortal($params),
        ];
        $checkAgain = signnet_checkDomain($params);

        self::assertSame(array_fill_keys(array_keys($results), self::REFUSAL), $results);
        self::assertSame(self::CHECK_FAILED, $checkAgain, 'The customer is not shown another client\'s portal.');
        self::assertContains(
            'Sign.net: "Check again" on service #101 failed: ' . self::REFUSAL,
            WhmcsFake::activityMessages(),
        );
        self::assertSame([], $this->calls(), 'Sign.net is never called.');
        self::assertSame([], WhmcsFake::$apiCalls);
        $service = Rows::first(Capsule::table('tblhosting')->where('id', self::SERVICE_ID));
        self::assertSame(['', ''], [$service['username'] ?? null, $service['domain'] ?? null]);
        $link = $this->links->find(self::SERVICE_ID);
        self::assertSame(
            [self::OLD_TENANT_ID, ServiceLink::STATE_ACTIVE, self::ORDER_ID],
            [$link?->tenantId, $link?->state, $link?->orderId],
        );
    }

    #[Test]
    public function theNewerServicesPagesShowNothingOfThatPortal(): void
    {
        $view = signnet_ClientArea($this->newerServicesParams())['templateVariables']['signnet'];
        $fields = signnet_AdminServicesTabFields($this->newerServicesParams());

        self::assertSame(['setting_up', '', null], [$view['state'], $view['hostname'], $view['packageName']]);
        self::assertSame(['Sign.net portal' => 'Unavailable: ' . Html::escape(self::REFUSAL)], $fields);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function unlinkingThatPortalLetsCreateGiveTheNewerServiceItsOwn(): void
    {
        $params = $this->newerServicesParams();
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        $unlinked = signnet_unlinkPortal($params);
        $created = signnet_CreateAccount($params);

        self::assertSame(['success', 'success'], [$unlinked, $created]);
        self::assertSame('sign.new-client.test', $this->bodySentTo(R::PROVISION_CALL)['config']['domain'] ?? null);
        $link = $this->links->find(self::SERVICE_ID);
        self::assertSame(
            [R::TENANT_ID, 'sign.new-client.test', self::NEW_ORDER_ID],
            [$link?->tenantId, $link?->hostname, $link?->orderId],
        );
        self::assertContains(
            'Sign.net: service #101 unlinked from the portal sign.first-client.test (tenant ' . self::OLD_TENANT_ID
                . '); the portal itself was not changed.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function onceUnlinkedThePortalCanBeLinkedToTheServiceItBelongsTo(): void
    {
        $this->insertService(102, 'Active', 700);
        $theirs = $this->params(['serviceid' => 102, 'username' => self::OLD_TENANT_ID]);
        $this->queueToken();
        $this->transport->queueData(R::labels([self::OLD_TENANT_ID => self::OLD_HOST]));
        $this->transport->queueData(R::labels([self::OLD_TENANT_ID => self::OLD_HOST]));
        $this->transport->queueData(R::label(['tenantId' => self::OLD_TENANT_ID, 'primaryHost' => self::OLD_HOST]));

        $whileHeld = signnet_linkPortal($theirs);
        signnet_unlinkPortal($this->newerServicesParams());
        $onceUnlinked = signnet_linkPortal($theirs);

        self::assertSame('That portal is linked to service #101.', $whileHeld);
        self::assertSame('success', $onceUnlinked);
        $link = $this->links->find(102);
        self::assertSame([self::OLD_TENANT_ID, 700], [$link?->tenantId, $link?->orderId]);
    }

    #[Test]
    public function aLinkWhosePortalWasDeletedIsReplacedByTheNewerServicesOwn(): void
    {
        $deleted = $this->links->find(self::SERVICE_ID)?->with(['state' => ServiceLink::STATE_TERMINATED]);
        self::assertNotNull($deleted);
        $this->links->save($deleted);
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        self::assertSame('success', signnet_CreateAccount($this->newerServicesParams()));

        $link = $this->links->find(self::SERVICE_ID);
        self::assertSame([R::TENANT_ID, self::NEW_ORDER_ID], [$link?->tenantId, $link?->orderId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function newerServicesParams(): array
    {
        return $this->params([
            'userid' => self::NEW_CLIENT_ID,
            'customfields' => ['Portal address' => 'sign.new-client.test', 'Portal name' => 'New Sign'],
            'clientsdetails' => ['firstname' => 'Bo', 'lastname' => 'Chen', 'email' => 'bo@new-client.test'],
        ]);
    }
}
