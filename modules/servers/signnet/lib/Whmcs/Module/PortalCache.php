<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Model\AssignedAddon;
use SignNet\ResellerApi\Model\Assignment;
use SignNet\ResellerApi\Model\DnsRecord;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\Db\Cache;
use SignNet\Whmcs\Db\ServiceLink;

/**
 * A portal as Sign.net reports it, read at most once a minute: the service page and the client
 * area are opened far more often than a portal changes.
 */
final class PortalCache
{
    private const TTL_SECONDS = 60;

    /**
     * @throws PortalGoneException When Sign.net no longer has the portal.
     * @throws ApiException
     */
    public static function get(ResellerClient $client, ServiceLink $link, bool $fresh = false): PrivateLabel
    {
        $tenantId = (string) $link->tenantId;
        $cached = $fresh ? null : Cache::get(self::key($tenantId));
        if ($cached !== null) {
            try {
                return PrivateLabel::fromArray($cached);
            } catch (\UnexpectedValueException) {
                // Written by an older version of the plugin: read it again.
            }
        }
        $label = self::read($client, $link);
        Cache::put(self::key($tenantId), self::toArray($label), self::TTL_SECONDS);

        return $label;
    }

    /**
     * Sign.net answers a deleted portal with 403 forbidden, because it has left the reseller's
     * tenants; that answer for a portal it still lists is a real refusal.
     *
     * @throws PortalGoneException
     * @throws ApiException
     */
    private static function read(ResellerClient $client, ServiceLink $link): PrivateLabel
    {
        try {
            return $client->getPrivateLabel((string) $link->tenantId);
        } catch (ForbiddenException $exception) {
            if ($exception->errorCode !== ErrorCode::FORBIDDEN) {
                throw $exception;
            }
            foreach ($client->listPrivateLabels() as $label) {
                if ($label->tenantId === $link->tenantId) {
                    throw $exception;
                }
            }
            throw new PortalGoneException($link->hostname);
        }
    }

    public static function forget(string $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    private static function key(string $tenantId): string
    {
        return 'portal:' . $tenantId;
    }

    /**
     * The portal in the API's own format, so reading it back goes through the API client's checks.
     *
     * @return array<string, mixed>
     */
    private static function toArray(PrivateLabel $label): array
    {
        return [
            'tenantId' => $label->tenantId,
            'primaryHost' => $label->primaryHost,
            'customDomain' => $label->customDomain,
            'status' => $label->status,
            'createdAt' => $label->createdAt,
            'suspendedAt' => $label->suspendedAt,
            'suspendedBy' => $label->suspendedBy,
            'suspendReason' => $label->suspendReason,
            'owner' => $label->owner === null ? null : [
                'userId' => $label->owner->userId,
                'email' => $label->owner->email,
                'firstName' => $label->owner->firstName,
                'lastName' => $label->owner->lastName,
            ],
            'memberCount' => $label->memberCount,
            'assignment' => $label->package === null ? null : self::assignmentToArray($label->package),
            'domainSetup' => [
                'attached' => $label->domainSetup->attached,
                'verified' => $label->domainSetup->verified,
                'records' => array_map(
                    static fn (DnsRecord $record): array => [
                        'type' => $record->type,
                        'name' => $record->name,
                        'value' => $record->value,
                    ],
                    $label->domainSetup->records,
                ),
                'note' => $label->domainSetup->note,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function assignmentToArray(Assignment $assignment): array
    {
        return [
            'id' => $assignment->assignmentId,
            'packageId' => $assignment->packageId,
            'packageCode' => $assignment->code,
            'packageName' => $assignment->name,
            'currency' => $assignment->currency,
            'assignedAt' => $assignment->assignedAt,
            'addons' => array_map(
                static fn (AssignedAddon $addon): array => [
                    'subscriptionAddonId' => $addon->subscriptionAddonId,
                    'addonId' => $addon->addonId,
                    'code' => $addon->code,
                    'name' => $addon->name,
                    'quantity' => $addon->quantity,
                ],
                $assignment->addons,
            ),
            'allocated' => $assignment->allocated,
        ];
    }
}
