<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Model\AllocationOutcome;
use SignNet\ResellerApi\Model\AssignedAddon;
use SignNet\ResellerApi\Model\Assignment;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Support\ErrorText;

/**
 * Brings a portal's package and add-ons to the plan, reading what it holds now first, so running
 * it again after any failure picks up where it stopped. A portal Sign.net has only just created
 * with the plan's package holds nothing else yet, so attachAfterAssignment() skips that read.
 *
 * Over the allowance Sign.net writes nothing and asks for confirmation; the converger confirms
 * only when told to, and otherwise stops, reporting the shortfall.
 */
final class PlanConverger
{
    public function __construct(
        private readonly ResellerClient $client,
        private readonly AddonCatalogue $addons,
    ) {
    }

    /**
     * @throws PlanException When the plan names an add-on Sign.net does not have, a package swap would
     *     be to an archived package, or a swap failed (the message says what was put back).
     * @throws ApiException
     */
    public function converge(string $tenantId, DesiredPlan $plan, bool $confirmOverAllowance): ConvergeResult
    {
        $managed = $this->byAddonId($plan->addonQuantities);
        $current = $this->client->getAssignment($tenantId);
        $diff = PlanDiff::between($current, $plan->packageId, $managed);
        $notes = $diff->warnings;

        if ($diff->unassign) {
            $this->requireActive($plan->packageId);
            $this->client->unassignPackage($tenantId);
        }
        if ($diff->assign) {
            $swappedFrom = $diff->unassign ? $current : null;
            try {
                $outcome = $this->assign($tenantId, $plan->packageId, $confirmOverAllowance);
            } catch (ApiException $exception) {
                $restored = $this->restore($tenantId, $swappedFrom);
                if ($restored === null) {
                    throw $exception;
                }
                throw new PlanException($restored . ' ' . ErrorText::describe($exception), 0, $exception);
            }
            if ($outcome->isConfirmationRequired()) {
                $restored = $this->restore($tenantId, $swappedFrom);

                return ConvergeResult::held($outcome->warnings, $restored === null ? $notes : [...$notes, $restored]);
            }
        }
        foreach ($diff->detach as $attachment) {
            $this->client->removeAddon($tenantId, $attachment->subscriptionAddonId);
        }

        return $this->attachAll($tenantId, $diff->attach, $confirmOverAllowance, $notes);
    }

    /**
     * For a portal Sign.net has just created with the plan's package: attaches the add-ons ordered,
     * without reading the assignment, which holds no add-ons yet.
     *
     * @throws PlanException When the plan orders an add-on Sign.net does not have, or has archived.
     * @throws ApiException
     */
    public function attachAfterAssignment(
        string $tenantId,
        DesiredPlan $plan,
        bool $confirmOverAllowance,
    ): ConvergeResult {
        $ordered = array_filter($plan->addonQuantities, static fn (int $quantity): bool => $quantity > 0);

        return $this->attachAll($tenantId, $this->byAddonId($ordered), $confirmOverAllowance, []);
    }

    /**
     * @param array<array-key, int> $quantities Add-on code => quantity.
     *
     * @return array<string, int> Add-on id => quantity.
     *
     * @throws PlanException
     * @throws ApiException
     */
    private function byAddonId(array $quantities): array
    {
        $byId = [];
        foreach ($quantities as $code => $quantity) {
            $byId[$this->addons->require((string) $code, $quantity)->addonId] = $quantity;
        }

        return $byId;
    }

    /**
     * @param array<string, int> $quantities Add-on id => quantity.
     * @param list<string> $notes
     */
    private function attachAll(string $tenantId, array $quantities, bool $confirm, array $notes): ConvergeResult
    {
        foreach ($quantities as $addonId => $quantity) {
            $outcome = $this->attach($tenantId, $addonId, $quantity, $confirm);
            if ($outcome->isConfirmationRequired()) {
                return ConvergeResult::held($outcome->warnings, $notes);
            }
        }

        return ConvergeResult::done($notes);
    }

    /**
     * A swap ends the old package, and the credit the portal carried with it, before the new one is
     * assigned, so an archived package is caught first rather than costing the portal that credit.
     *
     * @throws PlanException
     * @throws ApiException
     */
    private function requireActive(string $packageId): void
    {
        $package = $this->client->getPackage($packageId);
        if (!$package->isActive) {
            throw new PlanException(sprintf('The package %s is archived in Sign.net.', $package->code));
        }
    }

    private function assign(string $tenantId, string $packageId, bool $confirm): AllocationOutcome
    {
        $outcome = $this->client->assignPackage($tenantId, $packageId);
        if ($outcome->isConfirmationRequired() && $confirm) {
            $outcome = $this->client->assignPackage($tenantId, $packageId, $outcome->confirmationKey);
        }

        return $outcome;
    }

    private function attach(string $tenantId, string $addonId, int $quantity, bool $confirm): AllocationOutcome
    {
        $outcome = $this->client->attachAddon($tenantId, $addonId, $quantity);
        if ($outcome->isConfirmationRequired() && $confirm) {
            $outcome = $this->client->attachAddon($tenantId, $addonId, $quantity, $outcome->confirmationKey);
        }

        return $outcome;
    }

    /**
     * After a swap that could not finish, puts the old package and its add-ons back (confirming,
     * since the portal held them a moment ago), so the portal is left as it was.
     *
     * @return string|null A note for the activity log; null when there was no swap.
     */
    private function restore(string $tenantId, ?Assignment $previous): ?string
    {
        if ($previous === null) {
            return null;
        }
        try {
            $isPutBack = $this->assign($tenantId, $previous->packageId, true)->isDone();
        } catch (ApiException $exception) {
            return sprintf(
                'The new package could not be assigned, and putting %s back failed too: %s',
                $previous->code,
                ErrorText::describe($exception),
            );
        }
        if (!$isPutBack) {
            return sprintf('The new package could not be assigned, and Sign.net did not put %s back.', $previous->code);
        }
        if ($previous->addons === []) {
            return sprintf('The new package could not be assigned, so %s was put back.', $previous->code);
        }
        $failure = $this->reattach($tenantId, $previous->addons);

        return $failure === null
            ? sprintf('The new package could not be assigned, so %s was put back with its add-ons.', $previous->code)
            : sprintf('The new package could not be assigned, so %s was put back, but %s', $previous->code, $failure);
    }

    /**
     * Attaches again the add-ons the portal held before the swap ended them. A rate limit ends the
     * attempt, because Sign.net would refuse the rest as well.
     *
     * @param list<AssignedAddon> $addons
     *
     * @return string|null Which add-ons are still missing and why; null when every one is back.
     */
    private function reattach(string $tenantId, array $addons): ?string
    {
        $missing = [];
        $reason = null;
        foreach ($addons as $index => $addon) {
            try {
                $isBack = $this->attach($tenantId, $addon->addonId, $addon->quantity, true)->isDone();
            } catch (RateLimitedException $exception) {
                $notTried = array_slice($addons, $index);

                return self::stillMissing([...$missing, ...$notTried], $reason ?? ErrorText::describe($exception));
            } catch (ApiException $exception) {
                $isBack = false;
                $reason ??= ErrorText::describe($exception);
            }
            if (!$isBack) {
                $missing[] = $addon;
            }
        }

        return $missing === [] ? null : self::stillMissing($missing, $reason);
    }

    /**
     * @param list<AssignedAddon> $addons
     */
    private static function stillMissing(array $addons, ?string $reason): string
    {
        $names = implode(', ', array_map(
            static fn (AssignedAddon $addon): string => sprintf('%s (x%d)', $addon->code, $addon->quantity),
            $addons,
        ));

        return sprintf('attaching %s to it again failed', $names) . ($reason === null ? '.' : ': ' . $reason);
    }
}
