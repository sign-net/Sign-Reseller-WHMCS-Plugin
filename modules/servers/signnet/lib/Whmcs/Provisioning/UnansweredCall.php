<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\Model\PrivateLabelSummary;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;

/**
 * Looks for the portal a service's unanswered provisioning call made.
 *
 * Sign.net has no idempotency keys, so that portal is known by what the call asked for: it is the
 * reseller's portal on the call's address, no other WHMCS service holds it, it was created after
 * the call was sent, and its owner has the client's email address. A portal may not have an owner
 * yet, so one without an owner still fits.
 */
final class UnansweredCall
{
    /** How far Sign.net's clock may be behind WHMCS's. */
    private const CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
    ) {
    }

    /**
     * @param ServiceLink $link Its attemptAt says when the unanswered call was sent.
     *
     * @return array{PrivateLabelSummary|null, PrivateLabel|null} The reseller's portal on $hostname, if
     *     it has one, and that portal read in full when it is the one the call made.
     *
     * @throws ApiException
     */
    public function find(ServiceLink $link, string $hostname, string $ownerEmail): array
    {
        foreach ($this->client->listPrivateLabels() as $label) {
            if ($label->primaryHost === $hostname) {
                return [$label, $this->madeBy($link, $label, $ownerEmail)];
            }
        }

        return [null, null];
    }

    /**
     * @throws ApiException
     */
    private function madeBy(ServiceLink $link, PrivateLabelSummary $label, string $ownerEmail): ?PrivateLabel
    {
        $holder = $this->links->findByTenant($label->tenantId);
        if ($link->attemptAt === null || ($holder !== null && $holder->serviceId !== $link->serviceId)) {
            return null;
        }
        $portal = $this->client->getPrivateLabel($label->tenantId);
        $isCreatedAfterTheCall = $portal->createdAt >= ($link->attemptAt - self::CLOCK_SKEW_SECONDS) * 1000;
        $isTheClients = $portal->owner === null || strcasecmp($portal->owner->email, $ownerEmail) === 0;

        return $isCreatedAfterTheCall && $isTheClients ? $portal : null;
    }
}
