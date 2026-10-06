<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Module;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\Db\Cache;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use WHMCS\Database\Capsule;

/**
 * The module as WHMCS drives it: through its signnet_* functions.
 */
final class ServerModuleTest extends ModuleTestCase
{
    /** A portal nobody has suspended. */
    private const ACTIVE = [
        'status' => 'Active',
        'suspendedAt' => null,
        'suspendedBy' => null,
        'suspendReason' => null,
    ];

    private ServiceRepository $links;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../../modules/servers/signnet/signnet.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new ServiceRepository();
    }

    #[Test]
    public function itDeclaresItsSettingsInTheOrderWhmcsStoresThem(): void
    {
        self::assertSame('Sign.net Private Label', signnet_MetaData()['DisplayName']);
        self::assertSame(
            ['Sign.net package', 'Orders over the allowance', 'Automated termination'],
            array_keys(signnet_ConfigOptions()),
        );
        self::assertSame('signnet_PackageLoader', signnet_ConfigOptions()['Sign.net package']['Loader']);
        self::assertContains('approveOverAllowance', signnet_AdminCustomButtonArray());
        self::assertSame(['Check DNS again' => 'checkDomain'], signnet_ClientAreaAllowedFunctions());
    }

    #[Test]
    public function testConnectionPassesWithAKeyThatCanDoEverything(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::quota());

        self::assertSame(['success' => true, 'error' => ''], signnet_TestConnection($this->params()));
        self::assertSame([...self::FIRST_CALLS, R::QUOTA_CALL], $this->calls());
    }

    #[Test]
    public function testConnectionNamesTheScopesAKeyLacksBeforeCallingTheConsole(): void
    {
        $this->transport->queueJson(200, [
            'access_token' => self::ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'reseller:provision reseller:packages',
        ]);

        $result = signnet_TestConnection($this->params());

        self::assertSame([
            'success' => false,
            'error' => 'The API key lacks reseller:read. Create a key with reseller:read, reseller:provision and '
                . 'reseller:packages.',
        ], $result);
        self::assertSame([R::TOKEN_CALL], $this->calls());
    }

    #[Test]
    public function testConnectionSaysWhenTheResellerHasNoPlan(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());

        $result = signnet_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('not put your reseller account on a plan', $result['error']);
    }

    #[Test]
    public function testConnectionExplainsARefusedKey(): void
    {
        $this->transport->queueJson(401, R::tokenError('invalid_client'));

        $result = signnet_TestConnection($this->params());

        self::assertFalse($result['success']);
        self::assertStringContainsString('Sign.net refused the API key', $result['error']);
    }

    #[Test]
    public function testConnectionAsksAboutARefusedKeyOnlyOnceInFiveMinutes(): void
    {
        $this->transport->queueJson(401, R::tokenError('invalid_client'));
        signnet_TestConnection($this->params());

        $again = signnet_TestConnection($this->params());

        self::assertFalse($again['success']);
        self::assertStringStartsWith('Token minting for this Sign.net API key is paused until ', $again['error']);
        self::assertStringEndsWith('A corrected key or hostname is tried straight away.', $again['error']);
        self::assertSame([R::TOKEN_CALL], $this->calls());
    }

    #[Test]
    public function testConnectionTriesACorrectedHostnameStraightAway(): void
    {
        $this->transport->queueJson(401, R::tokenError('invalid_client'));
        signnet_TestConnection($this->params(['serverhostname' => 'wrong-host.sign.test']));
        $this->queueToken();
        $this->transport->queueData(R::quota());

        self::assertSame(['success' => true, 'error' => ''], signnet_TestConnection($this->params()));
    }

    #[Test]
    public function createAccountProvisionsThroughTheEntryFunction(): void
    {
        $this->insertService();
        $this->queueToken();
        $this->transport->queueData(R::quota());
        $this->transport->queueData(R::package());
        $this->transport->queueData(R::provisioned(R::assigned()));

        self::assertSame('success', signnet_CreateAccount($this->params()));
        self::assertSame(R::TENANT_ID, $this->links->find(self::SERVICE_ID)?->tenantId);
        self::assertNoSecretsLogged();
    }

    #[Test]
    public function aServerRecordWithoutAKeyIsReportedNotThrown(): void
    {
        $result = signnet_CreateAccount($this->params(['serverpassword' => '']));

        self::assertStringContainsString('Password field', $result);
        self::assertSame('CreateAccount', WhmcsFake::$moduleCalls[0]['action']);
    }

    #[Test]
    public function theAdminTabShowsTheUnprovisionedState(): void
    {
        $this->insertService();

        $fields = signnet_AdminServicesTabFields($this->params());

        self::assertSame(['Sign.net portal' => 'Not provisioned yet.'], $fields);
    }

    #[Test]
    public function theAdminTabShowsWhatSignNetSaysAboutThePortal(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $this->queueToken();
        $this->transport->queueData(R::label());

        $fields = signnet_AdminServicesTabFields($this->params());

        self::assertStringContainsString('https://sign.acme.test', $fields['Sign.net portal']);
        self::assertStringContainsString('Suspended', $fields['Portal status']);
        self::assertStringContainsString('by you', $fields['Portal status']);
        self::assertStringContainsString('Reason: Unpaid invoice', $fields['Portal status']);
        self::assertSame('Ann Lee &lt;owner@acme.test&gt;', $fields['Owner']);
        self::assertStringContainsString('Starter (STARTER)', $fields['Package']);
        self::assertStringContainsString('SEATS5 × 2', $fields['Package']);
        self::assertStringContainsString('DNS pending', $fields['Domain']);
        self::assertStringContainsString('cname.vercel-dns.com', $fields['Domain']);
        self::assertStringContainsString(
            'WHMCS shows this service Active, but the portal is suspended.',
            $fields['Needs attention'],
        );
    }

    #[Test]
    public function aDeletedPortalIsShownWithoutAskingSignNet(): void
    {
        $this->insertService(self::SERVICE_ID, 'Terminated');
        $this->linkPortal(ServiceLink::STATE_TERMINATED);

        $fields = signnet_AdminServicesTabFields($this->params());
        $view = signnet_ClientArea($this->params())['templateVariables']['signnet'];

        self::assertStringContainsString('Deleted', $fields['Portal status']);
        self::assertSame('deleted', $view['state']);
        self::assertSame([], $this->calls());
        self::assertSame('This service\'s portal was deleted.', signnet_refresh($this->params()));
    }

    #[Test]
    public function aPortalDeletedOutsideWhmcsIsSaidToBeGone(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $this->queueToken();
        $this->transport->queueError(403, 'forbidden');
        $this->transport->queueData(R::dashboard());
        $this->transport->queueData(R::labels());

        $fields = signnet_AdminServicesTabFields($this->params());

        self::assertStringContainsString('no longer exists in Sign.net', $fields['Portal status']);
    }

    #[Test]
    public function aKeyThatCannotReadPortalsIsToldSo(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $this->queueToken();
        $this->transport->queueError(403, 'insufficient_scope');

        $fields = signnet_AdminServicesTabFields($this->params());

        self::assertStringContainsString('lacks a scope', $fields['Portal status']);
        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABEL], $this->calls());
    }

    #[Test]
    public function theAdminTabReadsSignNetAtMostOnceAMinute(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $this->queueToken();
        $this->transport->queueData(R::label());

        signnet_AdminServicesTabFields($this->params());
        signnet_AdminServicesTabFields($this->params());

        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABEL], $this->calls());
    }

    #[Test]
    public function aSuspendForgetsTheCachedPortal(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        Cache::put('portal:' . R::TENANT_ID, R::label(), 60);
        $this->queueToken();
        $this->transport->queueData(R::label());

        self::assertSame('success', signnet_SuspendAccount($this->params()));
        self::assertNull(Cache::get('portal:' . R::TENANT_ID));
    }

    #[Test]
    public function theClientAreaShowsTheRecordsStillToCreate(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $this->queueToken();
        $this->transport->queueData(R::label(self::ACTIVE));

        $area = signnet_ClientArea($this->params());

        self::assertSame('templates/overview.tpl', $area['tabOverviewModuleOutputTemplate']);
        $view = $area['templateVariables']['signnet'];
        self::assertSame('dns_pending', $view['state']);
        self::assertFalse($view['showLinks']);
        self::assertSame(
            [['type' => 'CNAME', 'name' => 'sign.acme.test', 'value' => 'cname.vercel-dns.com']],
            $view['dnsRecords'],
        );
        self::assertSame(WhmcsFake::TOKEN, $view['csrfToken']);
        self::assertSame(
            'clientarea.php?action=productdetails&id=101&modop=custom&a=checkDomain',
            $view['checkAgainUrl'],
        );
    }

    #[Test]
    public function theClientAreaLinksToALivePortal(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $domain = ['attached' => true, 'verified' => true, 'records' => [], 'note' => null];
        $this->queueToken();
        $this->transport->queueData(R::label(['domainSetup' => $domain] + self::ACTIVE));

        $view = signnet_ClientArea($this->params())['templateVariables']['signnet'];

        self::assertSame('active', $view['state']);
        self::assertTrue($view['showLinks']);
        self::assertSame('https://sign.acme.test', $view['portalUrl']);
        self::assertSame('https://sign.acme.test/organisation', $view['organisationUrl']);
        self::assertSame('Starter', $view['packageName']);
    }

    #[Test]
    public function theClientAreaListsWhatThePackageGivesAsTheAdminPagesNameIt(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $this->queueToken();
        $this->transport->queueData(R::label(self::ACTIVE));

        $view = signnet_ClientArea($this->params())['templateVariables']['signnet'];

        self::assertSame([
            ['item' => 'Documents', 'quantity' => 500],
            ['item' => 'Seats', 'quantity' => 20],
            ['item' => 'Templates', 'quantity' => 25],
        ], $view['allocated'], 'No notarisations means the reseller\'s pool, not a limit of none.');
    }

    #[Test]
    public function theClientAreaListsTheNotarisationsAPackageGives(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $assignment = ApiFixtures::assignment();
        $assignment['allocated'] = ['documents' => 500, 'seats' => 20, 'templates' => 25, 'notarizations' => 40];
        $this->queueToken();
        $this->transport->queueData(R::label(['assignment' => $assignment] + self::ACTIVE));

        $view = signnet_ClientArea($this->params())['templateVariables']['signnet'];

        self::assertContains(['item' => 'Notarisations', 'quantity' => 40], $view['allocated']);
    }

    #[Test]
    public function theClientAreaReadsAgainAPortalCachedByVersionOne(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        Cache::put('portal:' . R::TENANT_ID, ['tenant_id' => R::TENANT_ID, 'status' => 'active'], 60);
        $this->queueToken();
        $this->transport->queueData(R::label(self::ACTIVE));

        $view = signnet_ClientArea($this->params())['templateVariables']['signnet'];

        self::assertSame('dns_pending', $view['state']);
        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABEL], $this->calls());
    }

    #[Test]
    public function theClientAreaSaysAPendingPortalIsBeingSetUp(): void
    {
        $this->insertService();

        $view = signnet_ClientArea($this->params())['templateVariables']['signnet'];

        self::assertSame('setting_up', $view['state']);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function checkAgainAttachesAHostnameThatIsNotServedYetOnceAMinute(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
        $unattached = ['attached' => false, 'verified' => false, 'records' => [], 'note' => 'Not attached yet.'];
        $this->queueToken();
        $this->transport->queueData(R::label(['domainSetup' => $unattached]));
        $this->transport->queueData(['domainSetup' => ['attached' => true] + $unattached]);

        self::assertSame('success', signnet_checkDomain($this->params()));
        self::assertSame(
            [...self::FIRST_CALLS, 'GET ' . R::LABEL, 'POST ' . R::LABEL . '/domain/attach'],
            $this->calls(),
        );
        self::assertTrue($this->links->find(self::SERVICE_ID)?->domainSetup['attached'] ?? false);
        $calls = $this->calls();

        self::assertSame('You can check again in a minute.', signnet_checkDomain($this->params()));
        self::assertSame($calls, $this->calls(), 'No call within the minute.');
    }

    #[Test]
    public function linkExistingPortalFindsItByTheTenantIdInUsername(): void
    {
        $this->insertService();
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));

        self::assertSame('success', signnet_linkPortal($this->params(['username' => R::TENANT_ID])));

        $link = $this->links->find(self::SERVICE_ID);
        self::assertNotNull($link);
        self::assertSame(R::TENANT_ID, $link->tenantId);
        self::assertSame('sign.acme.test', $link->hostname);
    }

    #[Test]
    public function linkExistingPortalFindsItByTheHostnameInDomain(): void
    {
        $this->insertService();
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));

        self::assertSame('success', signnet_linkPortal($this->params(['domain' => 'Sign.Acme.test'])));
        self::assertSame(R::TENANT_ID, $this->links->find(self::SERVICE_ID)?->tenantId);
    }

    #[Test]
    public function linkExistingPortalRefusesOneAnotherServiceHolds(): void
    {
        $this->insertService();
        $this->links->save(new ServiceLink(202, $this->serverId, R::TENANT_ID, 'sign.acme.test'));
        $this->queueToken();
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));

        self::assertSame(
            'That portal is linked to service #202.',
            signnet_linkPortal($this->params(['username' => R::TENANT_ID])),
        );
    }

    #[Test]
    public function unlinkLeavesThePortalAlone(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();

        self::assertSame('success', signnet_unlinkPortal($this->params()));

        $link = $this->links->find(self::SERVICE_ID);
        self::assertNull($link?->tenantId);
        self::assertSame(ServiceLink::STATE_UNLINKED, $link?->state);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function approvingAHeldOrderRunsCreateThroughWhmcs(): void
    {
        $this->insertService();
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            hostname: 'sign.acme.test',
            packageState: ServiceLink::PACKAGE_HELD,
        ));
        WhmcsFake::$apiHandlers['ModuleCreate'] = static fn (array $values): array => ['result' => 'success'];

        self::assertSame('success', signnet_approveOverAllowance($this->params()));
        self::assertSame(
            [['command' => 'ModuleCreate', 'values' => ['serviceid' => self::SERVICE_ID]]],
            WhmcsFake::$apiCalls,
        );
        self::assertNotNull($this->links->find(self::SERVICE_ID)?->approvedAt);
    }

    #[Test]
    public function thePackageLoaderListsActivePackagesFromTheDefaultServer(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::packageList([
            ['id' => 'pkg-1', 'code' => 'STARTER', 'name' => 'Starter', 'isActive' => true]
                + ApiFixtures::packageSummary(),
            ['id' => 'pkg-2', 'code' => 'OLD', 'name' => 'Old', 'isActive' => false] + ApiFixtures::packageSummary(),
        ]));

        $options = signnet_PackageLoader(['serverhostname' => '']);

        self::assertSame(['pkg-1' => 'Starter (STARTER)'], $options);
        self::assertSame(
            'https://' . self::API_HOST . R::API . '/billing/packages/search',
            $this->transport->lastRequest()->url,
        );
    }

    #[Test]
    public function thePackageLoaderAsksForAPackageWhenThereIsNone(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::packageList());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Packages page');

        signnet_PackageLoader($this->params());
    }

    private function linkPortal(string $state = ServiceLink::STATE_ACTIVE): void
    {
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            R::TENANT_ID,
            'sign.acme.test',
            'Acme Sign',
            state: $state,
            packageState: ServiceLink::PACKAGE_ASSIGNED,
        ));
        Capsule::table('tblhosting')->where('id', self::SERVICE_ID)->update(['username' => R::TENANT_ID]);
    }
}
