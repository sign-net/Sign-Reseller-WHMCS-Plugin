<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

use SignNet\ResellerApi\Model\PrivateLabelSummary;
use SignNet\ResellerApi\Model\TenantStatus;
use SignNet\Whmcs\Admin\AddonConfig;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Support\Settings;
use WHMCS\Database\Capsule;

/**
 * What needs an administrator: orders held over the allowance, provisioning that failed or is in
 * doubt, portal addresses not attached, WHMCS and Sign.net disagreeing about a portal, live portals
 * whose WHMCS service is gone, and addon settings new portals ignore.
 *
 * @phpstan-type Service array{clientId: int, status: string}
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
            $service = $row['hosting_id'] === null
                ? null
                : ['clientId' => (int) $row['hosting_userid'], 'status' => (string) $row['hosting_status']];
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
        if ($service === null) {
            $orphan = self::orphan($link, $statuses);

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
        ]));
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
        if (!self::isLive($link) || ($link->domainSetup['attached'] ?? null) !== false) {
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
        if ($statuses === null || !self::isLive($link)) {
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
            default => null,
        };
    }

    /**
     * A live portal whose WHMCS service was deleted. When Sign.net's list shows the portal is gone
     * too, nothing is left to act on.
     *
     * @param Statuses|null $statuses
     */
    private static function orphan(ServiceLink $link, ?array $statuses): ?AttentionItem
    {
        $goneFromSignNet = $statuses !== null && !array_key_exists((string) $link->tenantId, $statuses);
        if (!self::isLive($link) || $goneFromSignNet) {
            return null;
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

    private static function isLive(ServiceLink $link): bool
    {
        return $link->isLinked()
            && in_array($link->state, [ServiceLink::STATE_ACTIVE, ServiceLink::STATE_SUSPENDED], true);
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
     * Every portal link with its WHMCS service's client and status (null columns when the service is gone).
     *
     * @return list<array<string, mixed>>
     */
    private static function linksWithServices(): array
    {
        return Rows::all(
            Capsule::table(Schema::SERVICES . ' as links')
                ->leftJoin('tblhosting as hosting', 'hosting.id', '=', 'links.service_id')
                ->select('links.*', 'hosting.id as hosting_id', 'hosting.userid as hosting_userid')
                ->addSelect('hosting.domainstatus as hosting_status')
                ->orderBy('links.service_id'),
        );
    }
}
