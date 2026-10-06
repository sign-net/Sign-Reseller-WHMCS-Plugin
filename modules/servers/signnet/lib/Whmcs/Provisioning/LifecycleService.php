<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\ValidationException;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Utf16;

/**
 * Suspends, unsuspends and deletes a service's portal.
 *
 * A suspension keeps the portal's people, documents and package, so the reseller is still billed
 * for it; deleting it is what ends that.
 */
final class LifecycleService
{
    /** Sign.net keeps at most this much of a suspension reason, in UTF-16 units. */
    private const MAX_REASON_LENGTH = 500;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
    ) {
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters; suspendreason is WHMCS's reason.
     */
    public function suspend(array $params): string
    {
        $link = $this->links->find((int) $params['serviceid']);
        if ($link === null || !$link->isLinked()) {
            return self::withoutPortal($link);
        }
        $reason = Utf16::truncate(trim((string) ($params['suspendreason'] ?? '')), self::MAX_REASON_LENGTH);
        try {
            $this->suspendWithReason((string) $link->tenantId, $reason, (int) ($params['userid'] ?? 0));
        } catch (ApiException $exception) {
            return ErrorText::describe($exception);
        }
        $this->links->save($link->with(['state' => ServiceLink::STATE_SUSPENDED, 'lastError' => null]));
        logActivity(
            sprintf('Sign.net: portal %s suspended for service #%d.', $link->hostname, $link->serviceId),
            (int) ($params['userid'] ?? 0),
        );

        return ProvisionService::SUCCESS;
    }

    /**
     * Suspends with WHMCS's reason, and without it when Sign.net refuses the reason: the reason is
     * optional, and a portal must never stay open because of it.
     *
     * @throws ApiException
     */
    private function suspendWithReason(string $tenantId, string $reason, int $clientId): void
    {
        if ($reason === '') {
            $this->client->suspendPrivateLabel($tenantId);

            return;
        }
        try {
            $this->client->suspendPrivateLabel($tenantId, $reason);
        } catch (ValidationException) {
            $this->client->suspendPrivateLabel($tenantId);
            logActivity('Sign.net refused the suspension reason, so the portal was suspended without it.', $clientId);
        }
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     */
    public function unsuspend(array $params): string
    {
        $link = $this->links->find((int) $params['serviceid']);
        if ($link === null || !$link->isLinked()) {
            return self::withoutPortal($link);
        }
        try {
            $this->client->unsuspendPrivateLabel((string) $link->tenantId);
        } catch (ConflictException $exception) {
            return $exception->errorCode === ErrorCode::SUSPENDED_BY_PLATFORM
                ? $this->platformSuspension((string) $link->tenantId)
                : ErrorText::describe($exception);
        } catch (ApiException $exception) {
            return ErrorText::describe($exception);
        }
        $this->links->save($link->with(['state' => ServiceLink::STATE_ACTIVE, 'lastError' => null]));
        logActivity(
            sprintf('Sign.net: portal %s unsuspended for service #%d.', $link->hostname, $link->serviceId),
            (int) ($params['userid'] ?? 0),
        );

        return ProvisionService::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     */
    public function terminate(array $params, TerminationGuard $guard): string
    {
        $link = $this->links->find((int) $params['serviceid']);
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

        return sprintf(
            'Suspended by Sign.net%s. Only Sign.net can lift this suspension; contact Sign.net support.',
            $reason === null || $reason === '' ? '' : ': ' . $reason,
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
