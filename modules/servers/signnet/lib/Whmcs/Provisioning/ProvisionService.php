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
use SignNet\ResellerApi\Model\ProvisionRequest;
use SignNet\ResellerApi\Model\Warning;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\NamedLock;
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
 * hostname Sign.net says is taken is adopted only when this service's own unanswered attempt
 * explains it.
 */
final class ProvisionService
{
    public const SUCCESS = 'success';

    /** Starts the message of an order held over the allowance, so it stands out in the module queue. */
    public const HELD_PREFIX = '[signnet:held] ';

    private const LOCK_WAIT_SECONDS = 5;

    private readonly AddonCatalogue $addons;
    private readonly PlanConverger $converger;

    public function __construct(
        private readonly ResellerClient $client,
        private readonly ServiceRepository $links,
        private readonly Settings $settings,
    ) {
        $this->addons = new AddonCatalogue($client);
        $this->converger = new PlanConverger($client, $this->addons);
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
            $link = $this->links->find((int) $params['serviceid'])
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
            $link = $this->links->find((int) $params['serviceid']);
            if ($link === null || !$link->isLinked()) {
                return 'This service has no Sign.net portal yet.';
            }

            return $this->finish($link, $this->plan($product, $params), $product, $params);
        });
    }

    /**
     * Lets the service's next run go past the allowance, once.
     */
    public function approveOverAllowance(int $serviceId): void
    {
        $link = $this->links->find($serviceId);
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
            } catch (InvalidOrderException | PlanException $exception) {
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
        $inDoubt = $link->attemptState === ServiceLink::ATTEMPT_UNKNOWN && $link->hostname === $order->hostname;
        $adopted = $inDoubt ? $this->adopt($link, $order, $params) : null;
        if ($adopted !== null) {
            return $this->finish($adopted, $this->plan($product, $params), $product, $params);
        }
        $link = self::withOrder($link, $order);

        return $this->refusal($link, $order, $product, $params)
            ?? $this->attempt($link, $order, $product, $params, $inDoubt);
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
            'attemptAt' => time(),
            'lastError' => null,
        ]));
        try {
            $result = $this->client->provisionPrivateLabel($this->request($order, $product));
        } catch (ConflictException $exception) {
            if ($exception->errorCode === ErrorCode::HOST_TAKEN) {
                return $this->onHostnameTaken($link, $order, $product, $params, $inDoubt);
            }

            return $this->fail(
                $link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]),
                ErrorText::describe($exception),
            );
        } catch (TransportException | ServerException $exception) {
            return $this->onLostAnswer($link, $order, $product, $params, $exception);
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
    private function onHostnameTaken(
        ServiceLink $link,
        OrderDetails $order,
        ProductSettings $product,
        array $params,
        bool $inDoubt,
    ): string {
        $adopted = $inDoubt ? $this->adopt($link, $order, $params) : null;
        if ($adopted !== null) {
            return $this->finish($adopted, $this->plan($product, $params), $product, $params);
        }

        return $this->fail($link->with(['attemptState' => ServiceLink::ATTEMPT_FAILED]), sprintf(
            '%s is already in use on Sign.net, and a hostname can never be reused. Ask the customer for another '
            . 'portal address, or use "Link existing portal" if the portal is already yours.',
            $order->hostname,
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function onLostAnswer(
        ServiceLink $link,
        OrderDetails $order,
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
            $adopted = $this->adopt($link, $order, $params);
        } catch (ApiException) {
            return $this->inDoubt($link, $exception);
        }
        if ($adopted !== null) {
            return $this->finish($adopted, $this->plan($product, $params), $product, $params);
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
            . 'the portal first. Details: %s',
            ErrorText::describe($exception),
        ));
    }

    /**
     * Links the service to the reseller's portal on the order's hostname, unless there is none or
     * another service already holds it.
     *
     * @param array<string, mixed> $params
     */
    private function adopt(ServiceLink $link, OrderDetails $order, array $params): ?ServiceLink
    {
        foreach ($this->client->listPrivateLabels() as $label) {
            if ($label->primaryHost !== $order->hostname) {
                continue;
            }
            $holder = $this->links->findByTenant($label->tenantId);
            if ($holder !== null && $holder->serviceId !== $link->serviceId) {
                return null;
            }
            $adopted = $this->links->save(self::withOrder($link, $order)->with([
                'tenantId' => $label->tenantId,
                'state' => $label->isSuspended() ? ServiceLink::STATE_SUSPENDED : ServiceLink::STATE_ACTIVE,
                'attemptState' => ServiceLink::ATTEMPT_NONE,
                'attemptAt' => null,
            ]));
            WhmcsServices::showPortal($adopted);
            logActivity(sprintf(
                'Sign.net: service #%d linked to the portal %s that its earlier, unanswered provisioning created.',
                $adopted->serviceId,
                $adopted->hostname,
            ), (int) ($params['userid'] ?? 0));

            return $adopted;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function linkTo(ServiceLink $link, string $tenantId, array $params, DomainSetup $domain): ServiceLink
    {
        $link = $this->links->save($link->with([
            'tenantId' => $tenantId,
            'state' => ServiceLink::STATE_ACTIVE,
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
        $this->links->save($link->with(['lastError' => $message]));

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
