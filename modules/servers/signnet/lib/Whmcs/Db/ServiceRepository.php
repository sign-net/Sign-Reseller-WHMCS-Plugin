<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use WHMCS\Database\Capsule;

final class ServiceRepository
{
    /**
     * The link saved under this service id, whichever service it was made for.
     */
    public function find(int $serviceId): ?ServiceLink
    {
        $row = Rows::first(Capsule::table(Schema::SERVICES)->where('service_id', $serviceId));

        return $row === null ? null : ServiceLink::fromRow($row);
    }

    /**
     * The service's link. WHMCS can give a deleted service's id to a newer service, and a link the
     * deleted service left is not the newer one's: while it manages a live portal it is refused, so
     * the newer service never takes that portal over, and otherwise the newer service has no link.
     *
     * @throws OrphanedLinkException
     */
    public function forService(int $serviceId): ?ServiceLink
    {
        $link = $this->find($serviceId);
        if ($link === null || $link->isForOrder(WhmcsServices::orderId($serviceId))) {
            return $link;
        }
        if ($link->isLive()) {
            throw new OrphanedLinkException($link);
        }

        return null;
    }

    public function findByTenant(string $tenantId): ?ServiceLink
    {
        $row = Rows::first(Capsule::table(Schema::SERVICES)->where('tenant_id', $tenantId));

        return $row === null ? null : ServiceLink::fromRow($row);
    }

    /** Another service of this server already using `$hostname`, in any state. */
    public function findOtherByHostname(int $serverId, string $hostname, int $exceptServiceId): ?ServiceLink
    {
        $row = Rows::first(
            Capsule::table(Schema::SERVICES)
                ->where('server_id', $serverId)
                ->where('hostname', $hostname)
                ->where('service_id', '!=', $exceptServiceId),
        );

        return $row === null ? null : ServiceLink::fromRow($row);
    }

    /** Whether another service has a provisioning call in doubt for `$hostname`. */
    public function hasOtherCallInDoubt(string $hostname, int $exceptServiceId): bool
    {
        return Capsule::table(Schema::SERVICES)
            ->where('hostname', $hostname)
            ->where('attempt_state', ServiceLink::ATTEMPT_UNKNOWN)
            ->where('service_id', '!=', $exceptServiceId)
            ->exists();
    }

    public function hostnameInUse(string $hostname): bool
    {
        return Capsule::table(Schema::SERVICES)->where('hostname', $hostname)->exists();
    }

    /** @return list<ServiceLink> */
    public function all(): array
    {
        return array_map(
            ServiceLink::fromRow(...),
            Rows::all(Capsule::table(Schema::SERVICES)->orderBy('service_id')),
        );
    }

    /**
     * Saves the link. A link saved for the first time records the WHMCS order of its service.
     */
    public function save(ServiceLink $link): ServiceLink
    {
        $now = time();
        $saved = $link->createdAt > 0 ? $link->with(['updatedAt' => $now]) : $link->with([
            'orderId' => $link->orderId ?? WhmcsServices::orderId($link->serviceId),
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        Capsule::table(Schema::SERVICES)->updateOrInsert(
            ['service_id' => $saved->serviceId],
            $saved->toRow(),
        );

        return $saved;
    }
}
