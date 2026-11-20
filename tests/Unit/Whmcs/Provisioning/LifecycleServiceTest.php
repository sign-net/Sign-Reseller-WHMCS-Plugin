<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\ClientFactory;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Provisioning\LifecycleService;
use SignNet\Whmcs\Provisioning\TerminationGuard;
use WHMCS\Database\Capsule;

final class LifecycleServiceTest extends ModuleTestCase
{
    private ServiceRepository $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new ServiceRepository();
        $this->links->save(new ServiceLink(
            self::SERVICE_ID,
            $this->serverId,
            R::TENANT_ID,
            'sign.acme.test',
            state: ServiceLink::STATE_ACTIVE,
        ));
    }

    #[Test]
    public function itSuspendsThePortalWithWhmcssReason(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedNow('Overdue on Payment'));

        $result = $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));

        self::assertSame('success', $result);
        self::assertSame([...self::FIRST_CALLS, 'POST ' . R::LABEL . '/suspend'], $this->calls());
        self::assertSame(['reason' => 'Overdue on Payment'], self::bodyOf($this->transport->lastRequest()));
        self::assertSame(ServiceLink::STATE_SUSPENDED, $this->link()->state);
        self::assertNull($this->link()->suspendedOutsideAt);
        self::assertContains(
            'Sign.net: portal sign.acme.test suspended for service #101.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function aReasonSignNetRefusesDoesNotKeepThePortalOpen(): void
    {
        $this->queueToken();
        $this->transport->queueError(400, 'invalid_request');
        $this->transport->queueData(self::suspendedNow(null));

        $result = $this->service()->suspend($this->params(['suspendreason' => 'Overdue']));

        self::assertSame('success', $result);
        self::assertSame(['reason' => 'Overdue'], self::bodyOf($this->requestsTo('POST ' . R::LABEL . '/suspend')[0]));
        self::assertSame([], self::bodyOf($this->transport->lastRequest()));
        self::assertSame(ServiceLink::STATE_SUSPENDED, $this->link()->state);
        self::assertNull($this->link()->suspendedOutsideAt, 'The suspension without a reason is still WHMCS\'s.');
        self::assertContains(
            'Sign.net refused the suspension reason, so the portal was suspended without it.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function itCutsALongReasonToWhatSignNetKeeps(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedNow(str_repeat("\u{1F600}", 250)));

        $this->service()->suspend($this->params(['suspendreason' => str_repeat("\u{1F600}", 400)]));

        self::assertSame(['reason' => str_repeat("\u{1F600}", 250)], self::bodyOf($this->transport->lastRequest()));
        self::assertNull($this->link()->suspendedOutsideAt);
    }

    #[Test]
    public function aSuspensionSignNetsClockPutsALittleBeforeTheCallIsStillWhmcss(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedSince(time() - 300, 'Overdue on Payment'));

        $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));

        self::assertNull($this->link()->suspendedOutsideAt);
    }

    #[Test]
    public function aSuspensionMadeInTheConsoleIsKeptFromWhmcssAutomaticUnsuspension(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedSince(time() - 3600, 'Abuse: phishing documents reported'));

        $suspended = $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));
        $calls = $this->calls();
        $unsuspended = $this->service()->unsuspend($this->params(), false);

        self::assertSame('success', $suspended, 'The portal is suspended, as WHMCS asked.');
        self::assertContains(
            'Sign.net: portal sign.acme.test was already suspended outside WHMCS, and stays so for service #101. '
                . 'WHMCS does not lift that suspension on its own.',
            WhmcsFake::activityMessages(),
        );
        self::assertSame(
            'Not unsuspended: the portal was suspended outside WHMCS, so WHMCS does not lift that suspension on its '
                . 'own. Lift it in the Sign.net console, or unsuspend the service from the WHMCS admin area.',
            $unsuspended,
        );
        self::assertSame($calls, $this->calls(), 'Sign.net is not asked to lift it.');
        self::assertSame(ServiceLink::STATE_SUSPENDED, $this->link()->state);
        self::assertNotNull($this->link()->suspendedOutsideAt);
    }

    #[Test]
    public function aSuspensionWithWhmcssReasonThatBeganBeforeTheCallIsNotWhmcss(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedSince(time() - 3600, 'Overdue on Payment'));

        $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));

        self::assertNotNull($this->link()->suspendedOutsideAt);
    }

    #[Test]
    public function anAdministratorLiftsASuspensionMadeOutsideWhmcs(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedSince(time() - 3600, 'Abuse: phishing documents reported'));
        $this->transport->queueData(self::active());

        $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));
        $result = $this->service()->unsuspend($this->params(), true);

        self::assertSame('success', $result);
        self::assertSame(
            [...self::FIRST_CALLS, 'POST ' . R::LABEL . '/suspend', 'POST ' . R::LABEL . '/unsuspend'],
            $this->calls(),
        );
        self::assertSame(ServiceLink::STATE_ACTIVE, $this->link()->state);
        self::assertNull($this->link()->suspendedOutsideAt);
        self::assertContains(
            'Sign.net: portal sign.acme.test unsuspended for service #101, lifting a suspension made outside WHMCS.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function itUnsuspendsTheSuspensionWhmcsMade(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::suspendedNow('Overdue on Payment'));
        $this->transport->queueData(self::active());

        $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));
        $result = $this->service()->unsuspend($this->params(), false);

        self::assertSame('success', $result);
        self::assertSame(
            [...self::FIRST_CALLS, 'POST ' . R::LABEL . '/suspend', 'POST ' . R::LABEL . '/unsuspend'],
            $this->calls(),
        );
        self::assertSame(ServiceLink::STATE_ACTIVE, $this->link()->state);
        self::assertContains(
            'Sign.net: portal sign.acme.test unsuspended for service #101.',
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function aRepeatOfWhmcssOwnSuspensionIsStillWhmcss(): void
    {
        $this->links->save($this->link()->with(['state' => ServiceLink::STATE_SUSPENDED]));
        $this->queueToken();
        $this->transport->queueData(self::suspendedSince(time() - 86400, 'Overdue on Payment'));
        $this->transport->queueData(self::active());

        $this->service()->suspend($this->params(['suspendreason' => 'Another overdue invoice']));
        $suspendedOutsideAt = $this->link()->suspendedOutsideAt;
        $result = $this->service()->unsuspend($this->params(), false);

        self::assertNull($suspendedOutsideAt);
        self::assertSame('success', $result);
        self::assertSame(ServiceLink::STATE_ACTIVE, $this->link()->state);
        self::assertCount(1, $this->requestsTo('POST ' . R::LABEL . '/unsuspend'));
    }

    #[Test]
    public function aPortalLiftedInTheConsoleThenSuspendedByWhmcsHoldsWhmcssSuspension(): void
    {
        $this->links->save($this->link()->withPortalSuspension(true));
        $this->queueToken();
        $this->transport->queueData(self::suspendedNow('Overdue on Payment'));

        $this->service()->suspend($this->params(['suspendreason' => 'Overdue on Payment']));

        self::assertSame(ServiceLink::STATE_SUSPENDED, $this->link()->state);
        self::assertNull($this->link()->suspendedOutsideAt);
    }

    #[Test]
    public function itSaysWhenOnlySignNetCanLiftASuspension(): void
    {
        $this->links->save($this->link()->withPortalSuspension(true));
        $this->queueToken();
        $this->transport->queueError(400, 'suspended_by_platform');
        $this->transport->queueData(R::label(['suspendedBy' => 'Platform', 'suspendReason' => 'Terms breach']));

        $result = $this->service()->unsuspend($this->params(), true);

        self::assertSame(
            'Suspended by Sign.net: Terms breach. Only Sign.net can lift this suspension; contact Sign.net support.',
            $result,
        );
        self::assertNotNull($this->link()->suspendedOutsideAt, 'It is still in force.');
    }

    #[Test]
    public function anAdministratorsTerminateDeletesThePortal(): void
    {
        $this->queueToken();
        $this->transport->queueData(['status' => 'deprovisioned']);

        $result = $this->service()->terminate($this->params(), new TerminationGuard(true));

        self::assertSame('success', $result);
        self::assertSame([...self::FIRST_CALLS, 'DELETE ' . R::LABEL], $this->calls());
        self::assertSame(ServiceLink::STATE_TERMINATED, $this->link()->state);
    }

    #[Test]
    public function automationDoesNotDeleteAPortalWithoutACancellationRequest(): void
    {
        $result = $this->service()->terminate($this->params(), new TerminationGuard(false));

        self::assertStringStartsWith('Not deleted:', $result);
        self::assertSame([], $this->calls());
        self::assertSame(ServiceLink::STATE_ACTIVE, $this->link()->state);
    }

    #[Test]
    public function automationDeletesAPortalTheClientAskedToCancel(): void
    {
        Capsule::table('tblcancelrequests')->insert(['relid' => self::SERVICE_ID, 'reason' => 'Moving on']);
        $this->queueToken();
        $this->transport->queueData(['status' => 'deprovisioned']);

        self::assertSame('success', $this->service()->terminate($this->params(), new TerminationGuard(false)));
    }

    #[Test]
    public function automationFollowsAlwaysAndNever(): void
    {
        $never = $this->service()->terminate($this->params(['configoption3' => 'never']), new TerminationGuard(false));
        self::assertStringStartsWith('Not deleted:', $never);

        $this->queueToken();
        $this->transport->queueData(['status' => 'deprovisioned']);
        $always = $this->service()->terminate(
            $this->params(['configoption3' => 'always']),
            new TerminationGuard(false),
        );
        self::assertSame('success', $always);
    }

    #[Test]
    public function terminatingAPortalThatIsAlreadyGoneSucceeds(): void
    {
        $this->queueToken();
        $this->transport->queueError(403, 'forbidden');
        $this->transport->queueData(R::dashboard());
        $this->transport->queueData(R::labels());

        self::assertSame('success', $this->service()->terminate($this->params(), new TerminationGuard(true)));
        self::assertSame(ServiceLink::STATE_TERMINATED, $this->link()->state);
    }

    #[Test]
    public function aRefusedTerminateOfAListedPortalIsReported(): void
    {
        $this->queueToken();
        $this->transport->queueError(403, 'forbidden');
        $this->transport->queueData(R::dashboard());
        $this->transport->queueData(R::labels([R::TENANT_ID => 'sign.acme.test']));

        $result = $this->service()->terminate($this->params(), new TerminationGuard(true));

        self::assertStringStartsWith('Sign.net refused: the portal is not one of yours', $result);
        self::assertSame(ServiceLink::STATE_ACTIVE, $this->link()->state);
    }

    #[Test]
    public function aKeyThatCannotDeleteIsToldSoWithoutLookingForThePortal(): void
    {
        $this->queueToken();
        $this->transport->queueError(403, 'insufficient_scope');

        $result = $this->service()->terminate($this->params(), new TerminationGuard(true));

        self::assertStringContainsString('lacks a scope', $result);
        self::assertSame([...self::FIRST_CALLS, 'DELETE ' . R::LABEL], $this->calls());
        self::assertSame(ServiceLink::STATE_ACTIVE, $this->link()->state);
    }

    #[Test]
    public function aServiceWithoutAPortalHasNothingToChange(): void
    {
        $this->links->save($this->link()->with(['tenantId' => null, 'state' => ServiceLink::STATE_UNLINKED]));

        self::assertSame('success', $this->service()->suspend($this->params()));
        self::assertSame('success', $this->service()->terminate($this->params(), new TerminationGuard(true)));
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function aPortalThatMayExistIsNotForgottenByTerminate(): void
    {
        $this->links->save($this->link()->with([
            'tenantId' => null,
            'attemptState' => ServiceLink::ATTEMPT_UNKNOWN,
        ]));

        $result = $this->service()->terminate($this->params(), new TerminationGuard(true));

        self::assertStringContainsString('Run Create first', $result);
    }

    /**
     * Sign.net's answer to a suspend call that made the suspension, giving it $reason.
     *
     * @return array<string, mixed>
     */
    private static function suspendedNow(?string $reason): array
    {
        return self::suspendedSince(time(), $reason);
    }

    /**
     * Sign.net's answer when the portal has been suspended by the reseller since $since (Unix
     * seconds), for $reason.
     *
     * @return array<string, mixed>
     */
    private static function suspendedSince(int $since, ?string $reason): array
    {
        return [
            'suspendedAt' => $since * 1000,
            'suspendedBy' => 'Reseller',
            'suspendReason' => $reason,
        ] + ApiFixtures::suspensionState();
    }

    /**
     * Sign.net's answer to an unsuspend call.
     *
     * @return array<string, mixed>
     */
    private static function active(): array
    {
        return ['status' => 'Active', 'suspendedAt' => null, 'suspendedBy' => null] + ApiFixtures::suspensionState();
    }

    private function service(): LifecycleService
    {
        return new LifecycleService(ClientFactory::fromParams($this->params()), $this->links);
    }

    private function link(): ServiceLink
    {
        $link = $this->links->find(self::SERVICE_ID);
        self::assertNotNull($link);

        return $link;
    }
}
