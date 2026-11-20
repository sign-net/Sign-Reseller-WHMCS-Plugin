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
 * Sign.net has no idempotency keys, so that portal is known by what the call asked for, which the
 * link saved before the call records: it is the reseller's portal on the call's address, no other
 * WHMCS service holds it or has a call of its own in doubt for that address, it was created after
 * the call was sent, and its owner has the email address the call gave, which may since have
 * changed on the client. A new portal can be listed before its owner exists, so one with no owner
 * fits only when looked at on a later run than the call's: right after a call, it may be another
 * order's portal, whose owner is still to come, while a portal with none later never gets one.
 */
final class UnansweredCall
{
    /** No portal is on the call's address. */
    public const NONE = 'none';
    /** The portal on the call's address is not the call's: it is older than the call, or another service holds it. */
    public const OTHERS = 'others';
    /** The portal on the call's address is the one the call made. */
    public const MADE = 'made';
    /** Right after the call, the portal on its address fits it but has no owner yet: a later run tells. */
    public const NO_OWNER_YET = 'no_owner_yet';
    /** The portal on the call's address was created since the call, but nothing shows that the call made it. */
    public const UNSURE = 'unsure';

    /** How long after it was sent an unanswered call may still be making its portal. */
    public const RUNNING_SECONDS = 600;

    /** How far Sign.net's clock may be behind WHMCS's. */
    private const CLOCK_SKEW_SECONDS = 600;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
    ) {
    }

    /**
     * @param ServiceLink $link As saved before the call: its hostname, owner email and attemptAt are
     *     the call's.
     * @param bool $isRightAfterTheCall Whether the call was sent in this run.
     *
     * @return array{string, PrivateLabel|null} What is on the call's address, as one of this class's
     *     constants, and the portal there read in full when it had to be.
     *
     * @throws ApiException
     */
    public function find(ServiceLink $link, bool $isRightAfterTheCall = false): array
    {
        $label = $this->listed($link->hostname);
        if ($label === null) {
            return [self::NONE, null];
        }
        $holder = $this->links->findByTenant($label->tenantId);
        if ($link->attemptAt === null || ($holder !== null && $holder->serviceId !== $link->serviceId)) {
            return [self::OTHERS, null];
        }
        $portal = $this->client->getPrivateLabel($label->tenantId);
        if ($portal->createdAt < ($link->attemptAt - self::CLOCK_SKEW_SECONDS) * 1000) {
            return [self::OTHERS, $portal];
        }
        if ($this->links->hasOtherCallInDoubt($link->hostname, $link->serviceId)) {
            return [self::UNSURE, $portal];
        }
        if ($portal->owner !== null) {
            return [strcasecmp($portal->owner->email, $link->ownerEmail) === 0 ? self::MADE : self::UNSURE, $portal];
        }

        return [$isRightAfterTheCall ? self::NO_OWNER_YET : self::MADE, $portal];
    }

    /**
     * Whether the call was sent too recently to be sure it has finished: one whose answer was lost may
     * still be making its portal.
     */
    public static function mayStillBeRunning(ServiceLink $link): bool
    {
        return $link->attemptAt !== null && time() - $link->attemptAt < self::RUNNING_SECONDS;
    }

    /**
     * @throws ApiException
     */
    private function listed(string $hostname): ?PrivateLabelSummary
    {
        foreach ($this->client->listPrivateLabels() as $label) {
            if ($label->primaryHost === $hostname) {
                return $label;
            }
        }

        return null;
    }
}
