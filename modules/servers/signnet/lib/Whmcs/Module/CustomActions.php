<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Model\PrivateLabelSummary;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\Cache;
use SignNet\Whmcs\Db\OrphanedLinkException;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Db\WhmcsServices;
use SignNet\Whmcs\Provisioning\DomainState;
use SignNet\Whmcs\Provisioning\HostnameNormalizer;
use SignNet\Whmcs\Provisioning\PlanException;
use SignNet\Whmcs\Provisioning\PortalSuspension;
use SignNet\Whmcs\Provisioning\ProvisionService;
use SignNet\Whmcs\Provisioning\WhmcsSuspension;

/**
 * The service page's Sign.net buttons, and the client area's "Check again".
 */
final class CustomActions
{
    /** What "Check again" answers within a minute of the last attempt. */
    public const CHECK_LATER = 'You can check again in a minute.';

    private const DNS_CHECK_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
    ) {
    }

    /**
     * Reads the portal from Sign.net now, rather than within the minute.
     *
     * @param array<string, mixed> $params
     *
     * @throws PlanException
     * @throws OrphanedLinkException
     * @throws ApiException
     */
    public function refresh(array $params): string
    {
        $link = $this->linked($params);
        $label = PortalCache::get($this->client, $link, true);
        $this->links->save(WhmcsSuspension::observe($link, PortalSuspension::of($label))->with([
            'domainSetup' => DomainState::toArray($label->domainSetup),
        ]));

        return ProvisionService::SUCCESS;
    }

    /**
     * Asks the hosting platform to serve the portal's hostname again, for when attaching failed.
     *
     * @param array<string, mixed> $params
     *
     * @throws PlanException
     * @throws OrphanedLinkException
     * @throws ApiException
     */
    public function retryDomainAttach(array $params): string
    {
        $link = $this->linked($params);
        $setup = $this->client->attachDomain((string) $link->tenantId);
        $this->links->save($link->with(['domainSetup' => DomainState::toArray($setup)]));
        PortalCache::forget((string) $link->tenantId);
        if (!$setup->attached) {
            return sprintf(
                'Sign.net could not attach %s yet%s',
                $link->hostname,
                $setup->note === null ? '.' : ': ' . $setup->note,
            );
        }

        return ProvisionService::SUCCESS;
    }

    /**
     * Sends the portal's owner a new set-password email; Sign.net allows one every five minutes.
     *
     * @param array<string, mixed> $params
     *
     * @throws PlanException
     * @throws OrphanedLinkException
     * @throws ApiException
     */
    public function resendOwnerInvite(array $params): string
    {
        $link = $this->linked($params);
        $owner = PortalCache::get($this->client, $link)->owner;
        if ($owner === null) {
            return 'The portal has no owner account to invite.';
        }
        $this->client->resendInvite((string) $link->tenantId, $owner->userId);
        logActivity(
            sprintf('Sign.net: set-password email resent to the owner of %s.', $link->hostname),
            (int) ($params['userid'] ?? 0),
        );

        return ProvisionService::SUCCESS;
    }

    /**
     * Links the service to a portal that already exists: the one whose tenant id is in the
     * service's Username field, or else whose hostname is in its Domain field. The portal may have
     * been made anywhere, for anyone, so the activity log names its owner.
     *
     * @param array<string, mixed> $params
     *
     * @throws OrphanedLinkException
     * @throws ApiException
     */
    public function linkPortal(array $params): string
    {
        $serviceId = (int) $params['serviceid'];
        $link = $this->links->forService($serviceId)
            ?? new ServiceLink($serviceId, (int) ($params['serverid'] ?? 0));
        if ($link->isLinked()) {
            return 'This service already manages a portal. Unlink it first.';
        }
        $label = $this->findPortal($params);
        if ($label === null) {
            return 'No portal of yours matches the tenant id in Username or the hostname in Domain.';
        }
        $holder = $this->links->findByTenant($label->tenantId);
        if ($holder !== null) {
            return sprintf('That portal is linked to service #%d.', $holder->serviceId);
        }
        $portal = $this->client->getPrivateLabel($label->tenantId);
        $link = WhmcsSuspension::observe($link, PortalSuspension::of($portal))->with([
            'tenantId' => $label->tenantId,
            'hostname' => $label->primaryHost,
            'attemptState' => ServiceLink::ATTEMPT_NONE,
            'attemptAt' => null,
            'lastError' => null,
        ]);
        WhmcsServices::showPortal($link);
        $this->links->save($link);
        logActivity(sprintf(
            'Sign.net: service #%d linked to the existing portal %s, %s.',
            $serviceId,
            $link->hostname,
            $portal->owner === null ? 'which has no owner account' : 'owned by ' . $portal->owner->email,
        ), (int) ($params['userid'] ?? 0));

        return ProvisionService::SUCCESS;
    }

    /**
     * Stops the service managing its portal, leaving the portal itself as it is. It also unlinks the
     * portal a deleted service left on the id WHMCS has given this one, so that this service can have
     * its own.
     *
     * @param array<string, mixed> $params
     *
     * @throws PlanException
     */
    public function unlinkPortal(array $params): string
    {
        $link = self::managing($this->links->find((int) $params['serviceid']));
        $this->links->save($link->with([
            'tenantId' => null,
            'state' => ServiceLink::STATE_UNLINKED,
            'attemptState' => ServiceLink::ATTEMPT_NONE,
            'packageState' => ServiceLink::PACKAGE_NONE,
            'heldWarnings' => [],
            'approvedAt' => null,
            'lastError' => null,
            'suspendAttemptAt' => null,
            'whmcsSuspendedAt' => null,
        ]));
        PortalCache::forget((string) $link->tenantId);
        logActivity(sprintf(
            'Sign.net: service #%d unlinked from the portal %s (tenant %s); the portal itself was not changed.',
            $link->serviceId,
            $link->hostname,
            (string) $link->tenantId,
        ), (int) ($params['userid'] ?? 0));

        return ProvisionService::SUCCESS;
    }

    /**
     * The customer's "Check again": reads the portal's domain setup now, and asks for the hostname
     * to be attached if it is not yet. Once a minute per service, whatever the outcome, so a check
     * that fails cannot be repeated any faster.
     *
     * @param array<string, mixed> $params
     *
     * @throws PlanException
     * @throws OrphanedLinkException
     * @throws ApiException
     */
    public function checkDomain(array $params): string
    {
        if (!Cache::claim('dns-check:' . (int) $params['serviceid'], self::DNS_CHECK_INTERVAL_SECONDS)) {
            return self::CHECK_LATER;
        }
        $link = $this->linked($params);
        $setup = PortalCache::get($this->client, $link, true)->domainSetup;
        if (!$setup->attached) {
            $setup = $this->client->attachDomain((string) $link->tenantId);
            PortalCache::forget((string) $link->tenantId);
        }
        $this->links->save($link->with(['domainSetup' => DomainState::toArray($setup)]));

        return ProvisionService::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws PlanException When the service manages no portal.
     * @throws OrphanedLinkException
     */
    private function linked(array $params): ServiceLink
    {
        return self::managing($this->links->forService((int) $params['serviceid']));
    }

    /**
     * @throws PlanException When the link manages no portal.
     */
    private static function managing(?ServiceLink $link): ServiceLink
    {
        if ($link === null || !$link->isLinked()) {
            throw new PlanException('This service has no Sign.net portal.');
        }
        if ($link->state === ServiceLink::STATE_TERMINATED) {
            throw new PlanException('This service\'s portal was deleted.');
        }

        return $link;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws ApiException
     */
    private function findPortal(array $params): ?PrivateLabelSummary
    {
        $username = strtolower(trim((string) ($params['username'] ?? '')));
        $tenantId = preg_match(WhmcsServices::TENANT_ID_PATTERN, $username) === 1 ? $username : null;
        $domain = trim((string) ($params['domain'] ?? ''));
        $hostname = $domain !== '' && HostnameNormalizer::isValid(strtolower($domain)) ? strtolower($domain) : null;
        foreach ($this->client->listPrivateLabels() as $label) {
            if ($label->tenantId === $tenantId || ($tenantId === null && $label->primaryHost === $hostname)) {
                return $label;
            }
        }

        return null;
    }
}
