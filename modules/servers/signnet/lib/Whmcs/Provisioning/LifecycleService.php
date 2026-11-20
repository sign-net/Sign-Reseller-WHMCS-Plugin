<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Exception\ValidationException;
use SignNet\ResellerApi\Model\SuspensionState;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\OrphanedLinkException;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Support\ErrorText;

/**
 * Suspends, unsuspends and deletes a service's portal.
 *
 * A suspension keeps the portal's people, documents and package, so the reseller is still billed
 * for it; deleting it is what ends that. On its own, WHMCS lifts only the suspension it made, which
 * it checks with Sign.net first: one made in the Sign.net console waits for an administrator, and
 * one made by Sign.net for Sign.net.
 */
final class LifecycleService
{
    /** How far Sign.net's clock may be behind WHMCS's. */
    private const CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
    ) {
    }

    /**
     * Suspends the portal. A portal that is already suspended stays suspended as it was, and Sign.net
     * answers with that suspension, so its answer shows whether a call of this service made it: this
     * one, or an earlier one whose answer never arrived, which may have suspended the portal anyway.
     *
     * @param array<string, mixed> $params WHMCS module parameters; suspendreason is WHMCS's reason.
     *
     * @throws OrphanedLinkException
     */
    public function suspend(array $params): string
    {
        $link = $this->links->forService((int) $params['serviceid']);
        if ($link === null || !$link->isLinked()) {
            return self::withoutPortal($link);
        }
        $clientId = (int) ($params['userid'] ?? 0);
        $reason = SuspensionReason::fromWhmcs((string) ($params['suspendreason'] ?? ''));
        $sentAt = $link->suspendAttemptAt ?? time();
        $this->links->save($link->with(['suspendAttemptAt' => $sentAt]));
        try {
            [$answer, $reasonGiven] = $this->suspendWithReason((string) $link->tenantId, $reason, $clientId);
        } catch (ApiException $exception) {
            if (!self::mayHaveArrived($exception)) {
                $this->links->save($link);
            }

            return ErrorText::describe($exception);
        }
        $suspension = PortalSuspension::of($answer) ?? new PortalSuspension(null, false, $reasonGiven);
        $suspended = self::isMadeByTheCall($suspension, $reasonGiven, $sentAt)
            ? $link->withWhmcsSuspension($suspension->startedAt)
            : WhmcsSuspension::observe($link, $suspension);
        $this->links->save($suspended->with(['suspendAttemptAt' => null, 'lastError' => null]));
        logActivity(sprintf(
            $suspended->suspendedOutsideAt === null
                ? 'Sign.net: portal %s suspended for service #%d.'
                : 'Sign.net: portal %s was already suspended outside WHMCS, and stays so for service #%d. WHMCS does '
                    . 'not lift that suspension on its own.',
            $link->hostname,
            $link->serviceId,
        ), $clientId);

        return ProvisionService::SUCCESS;
    }

    /**
     * Suspends with WHMCS's reason, and without it when Sign.net refuses the reason: the reason is
     * optional, and a portal must never stay open because of it.
     *
     * @return array{SuspensionState, string} Sign.net's answer, and the reason the suspending call gave.
     *
     * @throws ApiException
     */
    private function suspendWithReason(string $tenantId, string $reason, int $clientId): array
    {
        if ($reason === '') {
            return [$this->client->suspendPrivateLabel($tenantId), ''];
        }
        try {
            return [$this->client->suspendPrivateLabel($tenantId, $reason), $reason];
        } catch (ValidationException) {
            $suspension = $this->client->suspendPrivateLabel($tenantId);
            logActivity('Sign.net refused the suspension reason, so the portal was suspended without it.', $clientId);

            return [$suspension, ''];
        }
    }

    /**
     * Whether a call that failed this way may still have reached Sign.net: its answer was lost, or was
     * one this client could not read.
     */
    private static function mayHaveArrived(ApiException $exception): bool
    {
        return $exception instanceof ServerException
            || ($exception instanceof TransportException && $exception->requestSent !== false);
    }

    /**
     * Whether the suspension Sign.net answered with is one a suspend call of this service made: it is
     * the reseller's, it has the reason the call gave, and it did not begin before $sentAt, when the
     * first of the service's suspend calls still unanswered was sent.
     */
    private static function isMadeByTheCall(PortalSuspension $suspension, string $reason, int $sentAt): bool
    {
        $beganAt = $suspension->startedAt === null ? $sentAt : intdiv($suspension->startedAt, 1000);

        return !$suspension->isSignNets
            && $suspension->reason === $reason
            && $beganAt >= $sentAt - self::CLOCK_SKEW_SECONDS;
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     * @param bool $isAdministratorAction An administrator's Unsuspend lifts any suspension the reseller
     *     may lift. WHMCS's automation, such as unsuspending once the client pays, first reads the
     *     portal, and lifts only the suspension WHMCS made.
     *
     * @throws OrphanedLinkException
     */
    public function unsuspend(array $params, bool $isAdministratorAction): string
    {
        $link = $this->links->forService((int) $params['serviceid']);
        if ($link === null || !$link->isLinked()) {
            return self::withoutPortal($link);
        }
        if ($isAdministratorAction) {
            return $this->lift($link, $params);
        }
        try {
            $suspension = PortalSuspension::of($this->client->getPrivateLabel((string) $link->tenantId));
        } catch (ApiException $exception) {
            return ErrorText::describe($exception);
        }
        if ($suspension === null) {
            return $this->findNothingToLift($link, $params);
        }
        $observed = WhmcsSuspension::observe($link, $suspension);
        if ($observed->suspendedOutsideAt === null) {
            return $this->lift($observed, $params);
        }
        $this->links->save($observed);

        return $suspension->isSignNets
            ? self::onlySignNetLifts($suspension->reason)
            : 'Not unsuspended: the portal was suspended outside WHMCS, so WHMCS does not lift that suspension '
                . 'on its own. Lift it in the Sign.net console, or unsuspend the service from the WHMCS admin area.';
    }

    /**
     * Lifts the portal's suspension.
     *
     * @param array<string, mixed> $params WHMCS module parameters.
     */
    private function lift(ServiceLink $link, array $params): string
    {
        try {
            $this->client->unsuspendPrivateLabel((string) $link->tenantId);
        } catch (ConflictException $exception) {
            return $exception->errorCode === ErrorCode::SUSPENDED_BY_PLATFORM
                ? $this->platformSuspension((string) $link->tenantId)
                : ErrorText::describe($exception);
        } catch (ApiException $exception) {
            return ErrorText::describe($exception);
        }
        $this->links->save($link->withoutSuspension()->with(['lastError' => null]));
        logActivity(sprintf(
            'Sign.net: portal %s unsuspended for service #%d%s.',
            $link->hostname,
            $link->serviceId,
            $link->suspendedOutsideAt !== null ? ', lifting a suspension made outside WHMCS' : '',
        ), (int) ($params['userid'] ?? 0));

        return ProvisionService::SUCCESS;
    }

    /**
     * The portal holds no suspension any more, so there is nothing to lift. Sign.net is not asked to
     * lift one anyway, which would lift a suspension made since the portal was read.
     *
     * @param array<string, mixed> $params WHMCS module parameters.
     */
    private function findNothingToLift(ServiceLink $link, array $params): string
    {
        $this->links->save($link->withoutSuspension()->with(['lastError' => null]));
        logActivity(sprintf(
            'Sign.net: portal %s was no longer suspended, so nothing was lifted for service #%d.',
            $link->hostname,
            $link->serviceId,
        ), (int) ($params['userid'] ?? 0));

        return ProvisionService::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     *
     * @throws OrphanedLinkException
     */
    public function terminate(array $params, TerminationGuard $guard): string
    {
        $link = $this->links->forService((int) $params['serviceid']);
        if ($link === null || !$link->isLinked() || $link->state === ServiceLink::STATE_TERMINATED) {
            return self::withoutPortal($link);
        }
        $refusal = $guard->refusal($link->serviceId, ProductSettings::fromParams($params));
        if ($refusal !== null) {
            return $refusal;
        }
        try {
            $this->deprovision((string) $link->tenantId);
        } catch (ApiException $exception) {
            return ErrorText::describe($exception);
        }
        $this->links->save($link->with(['state' => ServiceLink::STATE_TERMINATED, 'lastError' => null]));
        logActivity(sprintf(
            'Sign.net: portal %s deleted for service #%d. Its hostname can never be used again.',
            $link->hostname,
            $link->serviceId,
        ), (int) ($params['userid'] ?? 0));

        return ProvisionService::SUCCESS;
    }

    /**
     * Deletes the portal. Sign.net answers a repeat with 403 forbidden, because a deleted portal
     * leaves the reseller's tenants, so that answer for a portal no longer listed means it is
     * already gone.
     *
     * @throws ApiException
     */
    private function deprovision(string $tenantId): void
    {
        try {
            $this->client->deprovisionPrivateLabel($tenantId);
        } catch (ForbiddenException $exception) {
            if ($exception->errorCode !== ErrorCode::FORBIDDEN) {
                throw $exception;
            }
            foreach ($this->client->listPrivateLabels() as $label) {
                if ($label->tenantId === $tenantId) {
                    throw $exception;
                }
            }
        }
    }

    private function platformSuspension(string $tenantId): string
    {
        try {
            $reason = $this->client->getPrivateLabel($tenantId)->suspendReason;
        } catch (ApiException) {
            $reason = null;
        }

        return self::onlySignNetLifts((string) $reason);
    }

    private static function onlySignNetLifts(string $reason): string
    {
        return sprintf(
            'Suspended by Sign.net%s. Only Sign.net can lift this suspension; contact Sign.net support.',
            $reason === '' ? '' : ': ' . $reason,
        );
    }

    /**
     * What to answer when the service has no portal: nothing to change, unless an earlier
     * provisioning call may have made one that nobody linked.
     */
    private static function withoutPortal(?ServiceLink $link): string
    {
        if ($link !== null && !$link->isLinked() && $link->attemptState === ServiceLink::ATTEMPT_UNKNOWN) {
            return 'An earlier provisioning call for this service was never answered, so a portal may exist. '
                . 'Run Create first; it finds and links that portal.';
        }

        return ProvisionService::SUCCESS;
    }
}
