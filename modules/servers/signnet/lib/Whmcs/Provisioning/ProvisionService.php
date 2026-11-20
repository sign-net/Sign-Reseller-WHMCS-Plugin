<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Exception\ValidationException;
use SignNet\ResellerApi\Model\AllocationOutcome;
use SignNet\ResellerApi\Model\DomainSetup;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\Model\ProvisionRequest;
use SignNet\ResellerApi\Model\Warning;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\NamedLock;
use SignNet\Whmcs\Db\OrphanedLinkException;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Db\WhmcsServices;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Settings;

/**
 * Creates a service's portal and keeps its package and add-ons in step with the service.
 *
 * CreateAccount may run again after any failure, so a run first finishes what an earlier one
 * started: it records the attempt before asking Sign.net to provision, and when the answer is
 * lost it looks for the portal before trying again. Sign.net has no idempotency keys, so a
 * portal on the ordered hostname is adopted only when it fits this service's own unanswered
 * attempt (UnansweredCall).
 */
final class ProvisionService
{
    public const SUCCESS = 'success';

    /** Starts the message of an order held over the allowance, so it stands out in the module queue. */
    public const HELD_PREFIX = '[signnet:held] ';

    private const LOCK_WAIT_SECONDS = 5;

    private readonly AddonCatalogue $addons;
    private readonly PlanConverger $converger;
    private readonly UnansweredCall $unanswered;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
        private readonly Settings $settings,
    ) {
        $this->addons = new AddonCatalogue($client);
        $this->converger = new PlanConverger($client, $this->addons);
        $this->unanswered = new UnansweredCall($client, $links);
    }

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     *
     * @return string "success", or what to tell the administrator.
     */
    public function create(array $params): string
    {
        return $this->locked($params, function () use ($params): string {
            $product = ProductSettings::fromParams($params);
            if ($product->packageId === null) {
                return 'Choose a Sign.net package in the product\'s Module Settings.';
            }
            $order = OrderDetails::fromParams($params);
            $link = $this->links->forService((int) $params['serviceid'])
                ?? new ServiceLink((int) $params['serviceid'], (int) ($params['serverid'] ?? 0));
            if ($link->isLinked() && $link->state !== ServiceLink::STATE_TERMINATED) {
                WhmcsServices::showPortal($link);

                return $this->finish($link, $this->plan($product, $params), $product, $params);
            }

            return $this->provision($link, $order, $product, $params);
        });
    }

    /**
     * ChangePackage, and the Apply plan button: brings the portal to the package and add-ons the
     * service now has.
     *
     * @param array<string, mixed> $params WHMCS module parameters.
     */
    public function applyPlan(array $params): string
    {
        return $this->locked($params, function () use ($params): string {
            $product = ProductSettings::fromParams($params);
            if ($product->packageId === null) {
                return 'Choose a Sign.net package in the product\'s Module Settings.';
            }
            $link = $this->links->forService((int) $params['serviceid']);
            if ($link === null || !$link->isLinked()) {
                return 'This service has no Sign.net portal yet.';
            }

            return $this->finish($link, $this->plan($product, $params), $product, $params);
        });
    }

    /**
     * Lets the service's next run go past the allowance. That run uses the approval up, whether it
     * succeeds, fails or is held again, so a later change of plan is never confirmed with it.
     *
     * @throws PlanException
     * @throws OrphanedLinkException
     */
    public function approveOverAllowance(int $serviceId): void
    {
        $link = $this->links->forService($serviceId);
        if ($link === null) {
            throw new PlanException('This service has not been provisioned yet, so there is nothing to approve.');
        }
        $this->links->save($link->with(['approvedAt' => time()]));
    }

    /**
     * @param array<string, mixed> $params
     * @param \Closure(): string $work
     */
    private function locked(array $params, \Closure $work): string
    {
        $run = static function () use ($work): string {
            try {
                return $work();
            } catch (InvalidOrderException | PlanException | OrphanedLinkException $exception) {
                return $exception->getMessage();
            } catch (ApiException $exception) {
                return ErrorText::describe($exception);
            }
        };

        return NamedLock::run('provision:' . (int) $params['serviceid'], self::LOCK_WAIT_SECONDS, $run)
            ?? 'Another provisioning run for this service is still going. Try again in a minute.';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function provision(
        ServiceLink $link,
        OrderDetails $order,
        ProductSettings $product,
        array $params,
    ): string {
        $inDoubt = $link->attemptState === ServiceLink::ATTEMPT_UNKNOWN;
        if ($inDoubt && $link->hostname !== $order->hostname) {
            $settled = $this->settleBeforeNewAddress($link, $order, $params);
            if ($settled !== null) {
                return $settled;
            }
            // The earlier call made no portal at its own address, so nothing is in doubt any more.
            $link = $link->with(['attemptState' => ServiceLink::ATTEMPT_NONE, 'attemptAt' => null]);
            $inDoubt = false;
        }
        $settled = $inDoubt ? $this->settleUnanswered($link, $product, $params) : null;
        if ($settled !== null) {
            return $settled;
        }
        $link = self::withOrder($link, $order);

        return $this->refusal($link, $order, $product, $params)
            ?? $this->attempt($link, $order, $product, $params, $inDoubt);
    }

    /**
     * Before trying again, looks for the portal the service's unanswered provisioning call made. It
     * is linked with what the call asked for, which is what Sign.net made it with.
     *
     * @param array<string, mixed> $params
     *
     * @return string|null What the run answers; null when the call made no portal, so trying again
     *     is safe.
     */
    private function settleUnanswered(ServiceLink $link, ProductSettings $product, array $params): ?string
    {
        try {
            [$found, $portal] = $this->unanswered->find($link);
        } catch (ApiException $exception) {
            return $this->inDoubt($link, $exception);
        }
        if ($portal !== null && $found === UnansweredCall::MADE) {
            return $this->adoptAndFinish($link, $portal, $product, $params);
        }

        return $found === UnansweredCall::NONE ? null : $this->hostnameTaken($link);
    }

    /**
     * The order now names another address than the unanswered call asked for. A portal that call
     * made is linked rather than left behind, and it keeps its address. The new address is used only
     * once the call surely made none: its address is free and the call has had time to finish, or the
     * portal there is plainly another's.
     *
     * @param array<string, mixed> $params
     *
     * @return string|null What the run answers; null when the call made no portal, so the new
     *     address can be used.
     */
    private function settleBeforeNewAddress(ServiceLink $link, OrderDetails $order, array $params): ?string
    {
        try {
            [$found, $portal] = $this->unanswered->find($link);
        } catch (ApiException $exception) {
            return $this->fail($link, self::earlierCallUnresolved($link, $order) . sprintf(
                'looking for the portal it may have made on %s failed. Run Create again to look once more. '
                . 'Details: %s',
                $link->hostname,
                ErrorText::describe($exception),
            ));
        }
        if ($portal !== null && $found === UnansweredCall::MADE) {
            return $this->fail($this->adopt($link, $portal, $params), sprintf(
                'The portal address was not changed to %1$s. The earlier provisioning call, whose answer never '
                . 'arrived, made a portal on %2$s, and this service now manages it; the plugin cannot move a '
                . 'portal to another address. Set the service\'s portal address back to %2$s, then run Create '
                . 'again to finish.',
                $order->hostname,
                $link->hostname,
            ));
        }
        $why = self::whyTheNewAddressWaits($found, $link, $order);

        return $why === null ? null : $this->fail($link, self::earlierCallUnresolved($link, $order) . $why);
    }

    /**
     * Why the new address cannot be used yet, when the earlier call may have made a portal after all;
     * null when it surely made none.
     *
     * @param string $found What UnansweredCall found on the earlier address.
     */
    private static function whyTheNewAddressWaits(string $found, ServiceLink $link, OrderDetails $order): ?string
    {
        return match (true) {
            $found === UnansweredCall::UNSURE => sprintf(
                'a portal created on %1$s since may be the one it made, though nothing confirms it. If that portal '
                . 'is this service\'s, link it with "Link existing portal". If it is not, set the portal address '
                . 'back to %1$s and run Create, which then reports %1$s as in use; %2$s can be used after that.',
                $link->hostname,
                $order->hostname,
            ),
            $found === UnansweredCall::NONE && UnansweredCall::mayStillBeRunning($link) => sprintf(
                'it was sent less than ten minutes ago, so it may still make a portal on %s. Run Create again '
                . 'after %s UTC to look once more.',
                $link->hostname,
                gmdate('Y-m-d H:i', (int) $link->attemptAt + UnansweredCall::RUNNING_SECONDS),
            ),
            default => null,
        };
    }

    /**
     * How a message starts that keeps the new address waiting for the earlier call; the reason
     * follows.
     */
    private static function earlierCallUnresolved(ServiceLink $link, OrderDetails $order): string
    {
        return sprintf(
            'Resolve the earlier provisioning call for %s before using %s: its answer never arrived, and ',
            $link->hostname,
            $order->hostname,
        );
    }

    /**
     * Why the order cannot be provisioned now: its hostname is another service's, or it goes past
     * the allowance and nobody approved that.
     *
     * @param array<string, mixed> $params
     */
    private function refusal(ServiceLink $link, OrderDetails $order, ProductSettings $product, array $params): ?string
    {
        $holder = $this->links->findOtherByHostname($link->serverId, $order->hostname, $link->serviceId);
        if ($holder !== null) {
            return $this->fail($link, $holder->state === ServiceLink::STATE_TERMINATED
                ? sprintf(
                    'Service #%d used %s for a portal since deleted, and a hostname can never be reused.',
                    $holder->serviceId,
                    $order->hostname,
                )
                : sprintf('Service #%d already uses %s.', $holder->serviceId, $order->hostname));
        }
        if ($this->confirms($link, $product)) {
            return null;
        }
        $shortfalls = $this->shortfalls($product, $order);

        return $shortfalls === [] ? null : $this->hold($link, $shortfalls, $params);
    }

    /**
     * Asks Sign.net for the portal, recording the attempt first so a lost answer is looked into
     * on the next run.
     *
     * @param array<string, mixed> $params
     * @param bool $inDoubt An earlier call for this hostname went unanswered. The attempt keeps its
     *     time, so the portal it may have made still fits.
     */
    private function attempt(
        ServiceLink $link,
        OrderDetails $order,
        ProductSettings $product,
        array $params,
        bool $inDoubt,
    ): string {
        $link = $this->links->save($link->with([
            'state' => ServiceLink::STATE_PENDING,
            'attemptState' => ServiceLink::ATTEMPT_UNKNOWN,
            'attemptAt' => $inDoubt ? ($link->attemptAt ?? time()) : time(),
            'lastError' => null,
        ]));
        try {
            $result = $this->client->provisionPrivateLabel($this->request($order, $product));
        } catch (ConflictException $exception) {
            if ($exception->errorCode === ErrorCode::HOST_TAKEN) {
                return $this->onHostnameTaken($link, $product, $params, $inDoubt);
            }

            return $this->fail(
                $link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]),
                ErrorText::describe($exception),
            );
        } catch (TransportException | ServerException $exception) {
            return $this->onLostAnswer($link, $product, $params, $exception);
        } catch (ValidationException $exception) {
            return $this->fail(
                $link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]),
                $exception->errorCode === ErrorCode::INVALID_REQUEST
                    ? 'Sign.net refused the portal\'s details: check the portal address, the portal name and the '
                        . 'client\'s name and email address.'
                    : ErrorText::describe($exception),
            );
        } catch (ApiException $exception) {
            return $this->fail(
                $link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]),
                ErrorText::describe($exception),
            );
        }

        $link = $this->linkTo($link, $result->tenantId, $params, $result->domainSetup);
        if ($result->package !== null && $result->package->outcome === AllocationOutcome::FAILED) {
            return $this->fail($link->with(['packageState' => ServiceLink::PACKAGE_FAILED]), sprintf(
                'The portal was created, but Sign.net did not assign the package (%s). Run Create again to retry.',
                $result->package->error,
            ));
        }
        $isNewlyAssigned = $result->package?->outcome === AllocationOutcome::ASSIGNED;

        return $this->finish($link, $this->plan($product, $params), $product, $params, $isNewlyAssigned);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function onHostnameTaken(ServiceLink $link, ProductSettings $product, array $params, bool $inDoubt): string
    {
        if (!$inDoubt) {
            return $this->hostnameTaken($link);
        }
        try {
            [$found, $portal] = $this->unanswered->find($link, true);
        } catch (ApiException $exception) {
            return $this->inDoubt($link, $exception);
        }
        if ($portal !== null && $found === UnansweredCall::MADE) {
            return $this->adoptAndFinish($link, $portal, $product, $params);
        }

        return $found === UnansweredCall::NO_OWNER_YET ? $this->ownerStillToCome($link) : $this->hostnameTaken($link);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function onLostAnswer(
        ServiceLink $link,
        ProductSettings $product,
        array $params,
        ApiException $exception,
    ): string {
        if ($exception instanceof TransportException && $exception->requestSent === false) {
            return $this->fail(
                $link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]),
                ErrorText::describe($exception),
            );
        }
        try {
            [$found, $portal] = $this->unanswered->find($link, true);
        } catch (ApiException) {
            return $this->inDoubt($link, $exception);
        }
        if ($portal !== null && $found === UnansweredCall::MADE) {
            return $this->adoptAndFinish($link, $portal, $product, $params);
        }
        if ($found === UnansweredCall::NO_OWNER_YET) {
            return $this->ownerStillToCome($link);
        }
        if ($found !== UnansweredCall::NONE) {
            return $this->hostnameTaken($link);
        }
        // Sign.net answered in full, and its list has no such portal, so none was made. A call that
        // timed out may still be running there, so that one stays in doubt.
        if ($exception instanceof ServerException) {
            return $this->fail($link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]), sprintf(
                'Sign.net failed while creating the portal, and no portal was made. Running Create again tries '
                . 'again. Details: %s',
                ErrorText::describe($exception),
            ));
        }

        return $this->inDoubt($link, $exception);
    }

    private function inDoubt(ServiceLink $link, ApiException $exception): string
    {
        return $this->fail($link, sprintf(
            'Sign.net did not confirm that the portal was created. Running Create again is safe: it looks for '
            . 'the portal first. Do not change the portal address before it has. Details: %s',
            ErrorText::describe($exception),
        ));
    }

    /**
     * Right after the call, the portal on its address has no owner yet, so it may be another order's
     * portal on the same address, made at the same moment. The call stays in doubt for the next run.
     */
    private function ownerStillToCome(ServiceLink $link): string
    {
        return $this->fail($link, sprintf(
            'Sign.net did not confirm that the portal was created. A portal on %s may be the one this call made, '
            . 'but it has no owner yet, so it cannot be told apart from another order\'s. Run Create again in a '
            . 'minute: it links the portal if it still has no owner then, or if its owner has the email address '
            . 'this call gave.',
            $link->hostname,
        ));
    }

    /**
     * A portal that is not this service's has the link's hostname, so no attempt can create one there.
     */
    private function hostnameTaken(ServiceLink $link): string
    {
        return $this->fail($link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]), sprintf(
            '%s is already in use on Sign.net, and a hostname can never be reused. Ask the customer for another '
            . 'portal address, or use "Link existing portal" if the portal is already yours.',
            $link->hostname,
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function adoptAndFinish(
        ServiceLink $link,
        PrivateLabel $portal,
        ProductSettings $product,
        array $params,
    ): string {
        return $this->finish($this->adopt($link, $portal, $params), $this->plan($product, $params), $product, $params);
    }

    /**
     * Links the service to the portal that fits its unanswered provisioning call.
     *
     * @param array<string, mixed> $params
     */
    private function adopt(ServiceLink $link, PrivateLabel $portal, array $params): ServiceLink
    {
        $adopted = $this->links->save(WhmcsSuspension::observe($link, PortalSuspension::of($portal))->with([
            'tenantId' => $portal->tenantId,
            'attemptState' => ServiceLink::ATTEMPT_NONE,
            'attemptAt' => null,
            'domainSetup' => DomainState::toArray($portal->domainSetup),
        ]));
        WhmcsServices::showPortal($adopted);
        logActivity(sprintf(
            'Sign.net: service #%d linked to the portal %s. Its provisioning call went unanswered, and this portal '
            . 'fits that call: it has the address the call asked for, was created after the call was sent (allowing '
            . 'ten minutes for clock differences), and %s.',
            $adopted->serviceId,
            $adopted->hostname,
            $portal->owner === null
                ? 'has no owner yet'
                : sprintf('is owned by %s, the email address the call gave', $portal->owner->email),
        ), (int) ($params['userid'] ?? 0));

        return $adopted;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function linkTo(ServiceLink $link, string $tenantId, array $params, DomainSetup $domain): ServiceLink
    {
        $link = $this->links->save($link->withoutSuspension()->with([
            'tenantId' => $tenantId,
            'attemptState' => ServiceLink::ATTEMPT_NONE,
            'attemptAt' => null,
            'domainSetup' => DomainState::toArray($domain),
            'lastError' => null,
        ]));
        WhmcsServices::showPortal($link);
        logActivity(
            sprintf('Sign.net: portal %s created for service #%d.', $link->hostname, $link->serviceId),
            (int) ($params['userid'] ?? 0),
        );

        return $link;
    }

    /**
     * Brings a linked portal's package and add-ons to the plan and records how far it got.
     *
     * @param array<string, mixed> $params
     * @param bool $isNewlyAssigned Sign.net has just created the portal with the plan's package, so only
     *     the add-ons are left to do.
     */
    private function finish(
        ServiceLink $link,
        DesiredPlan $plan,
        ProductSettings $product,
        array $params,
        bool $isNewlyAssigned = false,
    ): string {
        $tenantId = (string) $link->tenantId;
        $confirm = $this->confirms($link, $product);
        try {
            $result = $isNewlyAssigned
                ? $this->converger->attachAfterAssignment($tenantId, $plan, $confirm)
                : $this->converger->converge($tenantId, $plan, $confirm);
        } catch (PlanException $exception) {
            return $this->fail($link->with(['packageState' => ServiceLink::PACKAGE_FAILED]), $exception->getMessage());
        } catch (ApiException $exception) {
            return $this->fail(
                $link->with(['packageState' => ServiceLink::PACKAGE_FAILED]),
                ErrorText::describe($exception),
            );
        }
        foreach ($result->notes as $note) {
            logActivity('Sign.net: ' . $note . ' (service #' . $link->serviceId . ')', (int) ($params['userid'] ?? 0));
        }
        if (!$result->isDone) {
            return $this->hold($link, $result->shortfalls, $params);
        }
        $this->links->save($link->with([
            'packageState' => ServiceLink::PACKAGE_ASSIGNED,
            'heldWarnings' => [],
            'approvedAt' => null,
            'lastError' => null,
        ]));

        return self::SUCCESS;
    }

    /**
     * @param list<Warning> $shortfalls
     * @param array<string, mixed> $params
     */
    private function hold(ServiceLink $link, array $shortfalls, array $params): string
    {
        $lines = array_map(AllowancePreCheck::describe(...), $shortfalls);
        $message = sprintf(
            'Held: this goes past your Sign.net allowance (%s). Approve it on the service with "Approve '
            . 'over-allowance & retry", or ask Sign.net to raise your allowance.',
            implode('; ', $lines),
        );
        $this->links->save($link->with([
            'packageState' => ServiceLink::PACKAGE_HELD,
            'heldWarnings' => $lines,
            'approvedAt' => null,
            'lastError' => $message,
        ]));
        logActivity(
            'Sign.net: service #' . $link->serviceId . ' held over the allowance.',
            (int) ($params['userid'] ?? 0),
        );

        return self::HELD_PREFIX . $message;
    }

    private function fail(ServiceLink $link, string $message): string
    {
        $this->links->save($link->with(['approvedAt' => null, 'lastError' => $message]));

        return $message;
    }

    /**
     * @return list<Warning>
     *
     * @throws PlanException
     * @throws ApiException
     */
    private function shortfalls(ProductSettings $product, OrderDetails $order): array
    {
        $quota = $this->client->getQuota();
        if (!$quota->hasPlan()) {
            throw new PlanException(
                'Sign.net has not put your reseller account on a plan yet, so no package can be assigned. '
                . 'Contact Sign.net.',
            );
        }
        $package = $this->client->getPackage((string) $product->packageId);
        if (!$package->isActive) {
            throw new PlanException(sprintf('The package %s is archived in Sign.net.', $package->code));
        }
        $addons = [];
        $ordered = array_filter($order->addonQuantities, static fn (int $quantity): bool => $quantity > 0);
        foreach ($ordered as $code => $quantity) {
            $addonId = $this->addons->require((string) $code, $quantity)->addonId;
            $addons[] = [$this->client->getAddon($addonId), $quantity];
        }

        return AllowancePreCheck::shortfalls($quota, AllowancePreCheck::requested($package, $addons));
    }

    private function request(OrderDetails $order, ProductSettings $product): ProvisionRequest
    {
        return new ProvisionRequest(
            $order->ownerEmail,
            $order->ownerFirstName,
            $order->ownerLastName,
            $order->hostname,
            $order->portalName,
            $this->settings->branding(),
            $product->packageId,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function plan(ProductSettings $product, array $params): DesiredPlan
    {
        return new DesiredPlan((string) $product->packageId, OrderDetails::addonQuantitiesFrom($params));
    }

    private function confirms(ServiceLink $link, ProductSettings $product): bool
    {
        return $product->confirmsOverAllowance() || $link->approvedAt !== null;
    }

    private static function withOrder(ServiceLink $link, OrderDetails $order): ServiceLink
    {
        return $link->with([
            'hostname' => $order->hostname,
            'portalName' => $order->portalName,
            'ownerEmail' => $order->ownerEmail,
        ]);
    }
}
