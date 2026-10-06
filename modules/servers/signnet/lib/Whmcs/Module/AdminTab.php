<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

use SignNet\ResellerApi\Model\AssignedAddon;
use SignNet\ResellerApi\Model\DnsRecord;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\Model\TenantStatus;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Support\Html;

/**
 * The Sign.net fields on a service's admin page (AdminServicesTabFields): what the portal is,
 * what Sign.net says about it now, and anything an administrator needs to act on.
 */
final class AdminTab
{
    /**
     * @param PrivateLabel|null $label Null when the service has no portal, or Sign.net could not be read.
     * @param string|null $error Why Sign.net could not be read.
     * @param string|null $whmcsStatus WHMCS's status for the service.
     *
     * @return array<string, string> Field label => HTML.
     */
    public static function fields(?ServiceLink $link, ?PrivateLabel $label, ?string $error, ?string $whmcsStatus): array
    {
        if ($link === null || !$link->isLinked()) {
            return ['Sign.net portal' => self::notLinked($link)] + self::attention($link, null, $whmcsStatus);
        }
        $fields = ['Sign.net portal' => self::portal($link)];
        if ($link->state === ServiceLink::STATE_TERMINATED) {
            $fields['Portal status'] = '<span class="label label-default">Deleted</span> The portal was deleted, '
                . 'and its hostname can never be used again.';

            return $fields + self::attention($link, null, $whmcsStatus);
        }
        if ($label === null) {
            $fields['Portal status'] = '<span class="label label-danger">Unavailable</span> '
                . Html::escape($error ?? '');

            return $fields + self::attention($link, null, $whmcsStatus);
        }

        return $fields + [
            'Portal status' => self::status($label),
            'Owner' => self::owner($label),
            'Package' => self::package($label),
            'Portal users' => Html::escape($label->memberCount),
            'Domain' => self::domain($label),
        ] + self::attention($link, $label, $whmcsStatus);
    }

    private static function notLinked(?ServiceLink $link): string
    {
        if ($link === null) {
            return 'Not provisioned yet.';
        }
        $text = $link->state === ServiceLink::STATE_UNLINKED
            ? 'Unlinked: this service no longer manages a portal.'
            : 'Not provisioned yet.';

        return Html::escape($link->hostname === '' ? $text : $text . ' Portal address: ' . $link->hostname);
    }

    private static function portal(ServiceLink $link): string
    {
        return sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> · tenant <code>%3$s</code>',
            Html::escape('https://' . $link->hostname),
            Html::escape($link->hostname),
            Html::escape((string) $link->tenantId),
        );
    }

    private static function status(PrivateLabel $label): string
    {
        if (!$label->isSuspended()) {
            return '<span class="label label-success">Active</span>';
        }
        $by = match ($label->suspendedBy) {
            TenantStatus::SUSPENDED_BY_PLATFORM => 'by Sign.net, and only Sign.net can lift it',
            TenantStatus::SUSPENDED_BY_RESELLER => 'by you',
            default => '',
        };
        $since = $label->suspendedAt === null ? '' : ' since ' . gmdate('Y-m-d', intdiv($label->suspendedAt, 1000));
        $reason = trim((string) $label->suspendReason);

        return '<span class="label label-warning">Suspended</span> '
            . Html::escape(trim($by . $since) . ($reason === '' ? '' : '. Reason: ' . $reason));
    }

    private static function owner(PrivateLabel $label): string
    {
        if ($label->owner === null) {
            return 'None';
        }

        return Html::escape(sprintf(
            '%s %s <%s>',
            $label->owner->firstName,
            $label->owner->lastName,
            $label->owner->email,
        ));
    }

    private static function package(PrivateLabel $label): string
    {
        $assignment = $label->package;
        if ($assignment === null) {
            return '<span class="label label-danger">None</span> The portal holds no package, so it spends '
                . 'straight from your allowance.';
        }
        $parts = [Html::escape(sprintf('%s (%s)', $assignment->name, $assignment->code))];
        if ($assignment->addons !== []) {
            $parts[] = 'Add-ons: ' . Html::escape(implode(', ', array_map(
                static fn (AssignedAddon $addon): string => sprintf('%s × %d', $addon->code, $addon->quantity),
                $assignment->addons,
            )));
        }
        if ($assignment->allocated !== []) {
            $allocated = [];
            foreach ($assignment->allocated as $itemCode => $quantity) {
                $allocated[] = sprintf('%s %d', $itemCode, $quantity);
            }
            $parts[] = 'Allocated: ' . Html::escape(implode(' · ', $allocated));
        }

        return implode('<br>', $parts);
    }

    private static function domain(PrivateLabel $label): string
    {
        $setup = $label->domainSetup;
        $state = match (true) {
            $setup->attached && $setup->verified => '<span class="label label-success">Live</span>',
            $setup->attached => '<span class="label label-warning">DNS pending</span>',
            default => '<span class="label label-danger">Not attached</span> Use "Retry domain attach".',
        };
        if ($setup->verified || $setup->records === []) {
            return $state;
        }
        $rows = array_map(
            static fn (DnsRecord $record): array => [
                Html::escape($record->type),
                '<code>' . Html::escape($record->name) . '</code>',
                '<code>' . Html::escape($record->value) . '</code>',
            ],
            $setup->records,
        );

        return $state . ($setup->note === null ? '' : ' ' . Html::escape($setup->note))
            . Html::table(['Type', 'Name', 'Value'], $rows);
    }

    /**
     * @return array<string, string>
     */
    private static function attention(?ServiceLink $link, ?PrivateLabel $label, ?string $whmcsStatus): array
    {
        $items = [];
        if ($link !== null && $link->attemptState === ServiceLink::ATTEMPT_UNKNOWN) {
            $items[] = 'A provisioning call was never answered, so a portal may exist. Run Create to find and link it.';
        }
        if ($link !== null && $link->lastError !== null) {
            $items[] = $link->lastError;
        }
        if ($label !== null && $whmcsStatus !== null) {
            $mismatch = self::mismatch($label, $whmcsStatus);
            if ($mismatch !== null) {
                $items[] = $mismatch;
            }
        }
        if ($items === []) {
            return [];
        }

        return ['Needs attention' => implode('<br>', array_map(
            static fn (string $item): string => '<span class="text-danger">' . Html::escape($item) . '</span>',
            $items,
        ))];
    }

    private static function mismatch(PrivateLabel $label, string $whmcsStatus): ?string
    {
        return match (true) {
            $whmcsStatus === 'Active' && $label->isSuspended() => 'WHMCS shows this service Active, but the '
                . 'portal is suspended.',
            $whmcsStatus === 'Suspended' && !$label->isSuspended() => 'WHMCS shows this service Suspended, but the '
                . 'portal is active.',
            in_array($whmcsStatus, ['Terminated', 'Cancelled'], true) => sprintf(
                'WHMCS shows this service %s, but the portal still exists.',
                $whmcsStatus,
            ),
            default => null,
        };
    }
}
