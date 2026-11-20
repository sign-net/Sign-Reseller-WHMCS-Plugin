<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

use SignNet\ResellerApi\Model\PrivateLabelSummary;
use SignNet\ResellerApi\Model\TenantStatus;
use SignNet\Whmcs\Admin\AddonConfig;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\WhmcsServices;
use SignNet\Whmcs\Support\Settings;
use WHMCS\Database\Capsule;

/**
 * What needs an administrator: orders held over the allowance, provisioning that failed or is in
 * doubt, portal addresses not attached, WHMCS and Sign.net disagreeing about a portal (a service
 * WHMCS has ended among them), suspensions made outside WHMCS on services it has suspended, live
 * portals whose WHMCS service is gone (also where WHMCS has given its id to a newer service), and
 * addon settings new portals ignore.
 *
 * @phpstan-type Service array{clientId: int, status: string, orderId: int}
 * @phpstan-type Statuses array<array-key, string>
 */
final class AttentionList
{
    /**
     * @param list<PrivateLabelSummary>|null $labels Sign.net's portals; null when they could not be
     *     read, which skips the checks that compare against them.
     * @param list<string> $invalidSettings As Settings::invalidSettings() names them.
     *
     * @return list<AttentionItem>
     */
    public function build(?array $labels, array $invalidSettings): array
    {
        $statuses = $labels === null ? null : self::statusByTenant($labels);
        $items = [];
        foreach (self::linksWithServices() as $row) {
            $service = $row['hosting_id'] === null ? null : [
                'clientId' => (int) $row['hosting_userid'],
                'status' => (string) $row['hosting_status'],
                'orderId' => (int) $row['hosting_orderid'],
            ];
            array_push($items, ...self::problems(ServiceLink::fromRow($row), $service, $statuses));
        }
        foreach ($invalidSettings as $setting) {
            $items[] = self::invalidSetting($setting);
        }

        return $items;
    }

    /**
     * @param Service|null $service
     * @param Statuses|null $statuses
     *
     * @return list<AttentionItem>
     */
    private static function problems(ServiceLink $link, ?array $service, ?array $statuses): array
    {
        if ($service === null || !$link->isForOrder($service['orderId'])) {
            $orphan = self::orphan($link, $service, $statuses);

            return $orphan === null ? [] : [$orphan];
        }
        if (in_array($link->state, [ServiceLink::STATE_TERMINATED, ServiceLink::STATE_UNLINKED], true)) {
            return [];
        }

        return array_values(array_filter([
            self::held($link, $service),
            self::provisioning($link, $service),
            self::domainNotAttached($link, $service),
            self::statusMismatch($link, $service, $statuses),
            self::suspendedOutside($link, $service, $statuses),
        ]));
    }

    /**
     * A suspended service whose portal holds a suspension WHMCS did not make, which WHMCS's automatic
     * unsuspension therefore leaves in place. Once Sign.net's list shows the portal active, that
     * suspension was lifted, and the status comparison says so instead.
     *
     * @param Service $service
     * @param Statuses|null $statuses
     */
    private static function suspendedOutside(ServiceLink $link, array $service, ?array $statuses): ?AttentionItem
    {
        $isLifted = $statuses !== null && ($statuses[(string) $link->tenantId] ?? null) === TenantStatus::ACTIVE;
        if ($link->suspendedOutsideAt === null || $service['status'] !== 'Suspended' || $isLifted) {
            return null;
        }

        return self::item($link, $service, 'Suspended outside WHMCS', [
            'The portal holds a suspension made outside WHMCS, so WHMCS does not lift it when the client pays. If you '
                . 'made it in the Sign.net console, lift it there, or unsuspend the service from its page in WHMCS. '
                . 'If Sign.net made it, only Sign.net can lift it.',
        ]);
    }

    /**
     * @param Service $service
     */
    private static function held(ServiceLink $link, array $service): ?AttentionItem
    {
        if ($link->packageState !== ServiceLink::PACKAGE_HELD) {
            return null;
        }

        return self::item($link, $service, 'Order held: it would go over your Sign.net allowance', [
            ...$link->heldWarnings,
            'Approve going over the allowance from the service\'s module commands, or free up allowance first.',
        ]);
    }

    /**
     * @param Service $service
     */
    private static function provisioning(ServiceLink $link, array $service): ?AttentionItem
    {
        $lastError = $link->lastError === null ? [] : [$link->lastError];

        return match ($link->attemptState) {
            ServiceLink::ATTEMPT_FAILED => self::item($link, $service, 'Provisioning failed', $lastError),
            ServiceLink::ATTEMPT_UNKNOWN => self::item($link, $service, 'Provisioning in doubt', [
                ...$lastError,
                'Sign.net\'s answer never arrived. Run Create again on the service: it adopts the portal if '
                    . 'Sign.net made it.',
            ]),
            default => null,
        };
    }

    /**
     * @param Service $service
     */
    private static function domainNotAttached(ServiceLink $link, array $service): ?AttentionItem
    {
        if (!$link->isLive() || ($link->domainSetup['attached'] ?? null) !== false) {
            return null;
        }

        return self::item($link, $service, 'Portal address not attached yet', [
            sprintf('Sign.net has not attached %s to its hosting yet, so the portal does not open.', $link->hostname),
        ]);
    }

    /**
     * @param Service $service
     * @param Statuses|null $statuses
     */
    private static function statusMismatch(ServiceLink $link, array $service, ?array $statuses): ?AttentionItem
    {
        if ($statuses === null || !$link->isLive()) {
            return null;
        }
        $tenantId = (string) $link->tenantId;
        if (!array_key_exists($tenantId, $statuses)) {
            return self::item($link, $service, 'Portal missing from Sign.net', [
                sprintf('Sign.net no longer lists tenant %s: it was deleted outside WHMCS.', $tenantId),
            ]);
        }

        return match (true) {
            $service['status'] === 'Active' && $statuses[$tenantId] === TenantStatus::SUSPENDED => self::item(
                $link,
                $service,
                'Suspended in Sign.net, Active in WHMCS',
                ['The portal was suspended outside WHMCS, possibly by Sign.net.'],
            ),
            $service['status'] === 'Suspended' && $statuses[$tenantId] === TenantStatus::ACTIVE => self::item(
                $link,
                $service,
                'Suspended in WHMCS, active in Sign.net',
                ['The portal still opens for its users.'],
            ),
            in_array($service['status'], WhmcsServices::ENDED_STATUSES, true) => self::item(
                $link,
                $service,
                sprintf('%s in WHMCS, live in Sign.net', $service['status']),
                ['The portal was not deleted, so you are still billed for its package, and its users can sign in '
                    . 'unless it is suspended. Run Terminate on the service to delete it.'],
            ),
            default => null,
        };
    }

    /**
     * A live portal whose WHMCS service was deleted. When Sign.net's list shows the portal is gone
     * too, nothing is left to act on.
     *
     * @param Service|null $service The newer service WHMCS has given the deleted one's id, if any.
     * @param Statuses|null $statuses
     */
    private static function orphan(ServiceLink $link, ?array $service, ?array $statuses): ?AttentionItem
    {
        $goneFromSignNet = $statuses !== null && !array_key_exists((string) $link->tenantId, $statuses);
        if (!$link->isLive() || $goneFromSignNet) {
            return null;
        }
        if ($service !== null) {
            return self::item($link, $service, 'WHMCS service deleted, portal still live', [sprintf(
                'WHMCS has given the deleted service\'s id to a newer service, which leaves this portal alone. '
                    . 'Note its tenant id, %2$s, before you click "Unlink (keeps the portal)" on service #%1$d: '
                    . 'after that, WHMCS lists the portal nowhere. Then terminate the portal in Sign.net, or link '
                    . 'it to another service.',
                $link->serviceId,
                (string) $link->tenantId,
            )]);
        }
        $detail = $link->lastError ?? sprintf(
            'Terminate the portal (tenant %s) in Sign.net, or link it to another service.',
            (string) $link->tenantId,
        );

        return new AttentionItem(
            'WHMCS service deleted, portal still live',
            [$detail],
            $link->serviceId,
            null,
            $link->hostname,
        );
    }

    private static function invalidSetting(string $setting): AttentionItem
    {
        $expected = array_key_exists($setting, Settings::URL_SETTINGS)
            ? 'a web address (https://…)'
            : 'a colour (#rrggbb)';

        return new AttentionItem(sprintf('Addon setting "%s" is not valid', AddonConfig::label($setting)), [
            sprintf(
                'It must be %s. New portals ignore it until it is fixed under System Settings > Addon Modules.',
                $expected,
            ),
        ]);
    }

    /**
     * @param Service $service
     * @param list<string> $details
     */
    private static function item(ServiceLink $link, array $service, string $problem, array $details): AttentionItem
    {
        return new AttentionItem($problem, $details, $link->serviceId, $service['clientId'], $link->hostname);
    }

    /**
     * @param list<PrivateLabelSummary> $labels
     *
     * @return Statuses
     */
    private static function statusByTenant(array $labels): array
    {
        $statuses = [];
        foreach ($labels as $label) {
            $statuses[$label->tenantId] = $label->status;
        }

        return $statuses;
    }

    /**
     * Every portal link with its WHMCS service's client, status and order (null columns when the service is
     * gone).
     *
     * @return list<array<string, mixed>>
     */
    private static function linksWithServices(): array
    {
        return Rows::all(
            Capsule::table(Schema::SERVICES . ' as links')
                ->leftJoin('tblhosting as hosting', 'hosting.id', '=', 'links.service_id')
                ->select('links.*', 'hosting.id as hosting_id', 'hosting.userid as hosting_userid')
                ->addSelect('hosting.domainstatus as hosting_status', 'hosting.orderid as hosting_orderid')
                ->orderBy('links.service_id'),
        );
    }
}
