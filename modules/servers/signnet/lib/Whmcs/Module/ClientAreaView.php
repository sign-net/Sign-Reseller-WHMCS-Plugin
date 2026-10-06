<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

use SignNet\ResellerApi\Model\AssignedAddon;
use SignNet\ResellerApi\Model\DnsRecord;
use SignNet\ResellerApi\Model\ItemCode;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Db\ServiceLink;

/**
 * What the customer sees on the service's Overview (templates/overview.tpl): their portal's
 * address and state, the DNS records still to create, and what their package includes.
 */
final class ClientAreaView
{
    public const SETTING_UP = 'setting_up';
    public const DNS_PENDING = 'dns_pending';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const DELETED = 'deleted';

    private const LABELS = [
        self::SETTING_UP => 'Setting up',
        self::DNS_PENDING => 'DNS pending',
        self::ACTIVE => 'Active',
        self::SUSPENDED => 'Suspended',
        self::DELETED => 'Deleted',
    ];

    /**
     * @param PrivateLabel|null $label Null when the service has no portal yet, or Sign.net could not be read.
     * @param bool $loadFailed Sign.net could not be read.
     * @param string|null $whmcsStatus WHMCS's status for the service.
     *
     * @return array<string, mixed> The template's variables.
     */
    public static function variables(
        int $serviceId,
        ?ServiceLink $link,
        ?PrivateLabel $label,
        bool $loadFailed,
        ?string $whmcsStatus,
    ): array {
        $state = self::state($link, $label);
        $portalUrl = $link === null || $link->hostname === '' ? null : 'https://' . $link->hostname;

        return [
            'state' => $state,
            'stateLabel' => self::LABELS[$state],
            'portalName' => $link->portalName ?? '',
            'hostname' => $link->hostname ?? '',
            'portalUrl' => $portalUrl,
            'organisationUrl' => $portalUrl === null ? null : $portalUrl . '/organisation',
            'showLinks' => $state === self::ACTIVE && $whmcsStatus === 'Active',
            'dnsRecords' => $label === null || $label->domainSetup->verified ? [] : array_map(
                static fn (DnsRecord $record): array => [
                    'type' => $record->type,
                    'name' => $record->name,
                    'value' => $record->value,
                ],
                $label->domainSetup->records,
            ),
            'packageName' => $label?->package?->name,
            'addons' => self::addons($label),
            'allocated' => self::allocated($label),
            'loadFailed' => $loadFailed,
            'checkAgainUrl' => sprintf(
                'clientarea.php?action=productdetails&id=%d&modop=custom&a=checkDomain',
                $serviceId,
            ),
        ];
    }

    private static function state(?ServiceLink $link, ?PrivateLabel $label): string
    {
        if ($link !== null && $link->state === ServiceLink::STATE_TERMINATED) {
            return self::DELETED;
        }
        if ($link === null || !$link->isLinked() || $label === null) {
            return $link !== null && $link->state === ServiceLink::STATE_SUSPENDED ? self::SUSPENDED : self::SETTING_UP;
        }
        if ($label->isSuspended()) {
            return self::SUSPENDED;
        }

        return $label->domainSetup->attached && $label->domainSetup->verified ? self::ACTIVE : self::DNS_PENDING;
    }

    /**
     * @return list<array{name: string, quantity: int}>
     */
    private static function addons(?PrivateLabel $label): array
    {
        return array_map(
            static fn (AssignedAddon $addon): array => ['name' => $addon->name, 'quantity' => $addon->quantity],
            $label?->package->addons ?? [],
        );
    }

    /**
     * @return list<array{item: string, quantity: int}>
     */
    private static function allocated(?PrivateLabel $label): array
    {
        $allocated = [];
        foreach ($label?->package->allocated ?? [] as $itemCode => $quantity) {
            // A portal given no notarisations notarises from the reseller's pool, so it has no limit to show.
            if ($itemCode === ItemCode::NOTARIZATIONS && $quantity === 0) {
                continue;
            }
            $allocated[] = ['item' => CatalogueLabels::item($itemCode), 'quantity' => $quantity];
        }

        return $allocated;
    }
}
