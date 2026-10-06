<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Db;

use WHMCS\Database\Capsule;

final class ServiceRepository
{
    public function find(int $serviceId): ?ServiceLink
    {
        $row = Rows::first(Capsule::table(Schema::SERVICES)->where('service_id', $serviceId));

        return $row === null ? null : ServiceLink::fromRow($row);
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

    public function save(ServiceLink $link): ServiceLink
    {
        $now = time();
        $saved = $link->with([
            'createdAt' => $link->createdAt > 0 ? $link->createdAt : $now,
            'updatedAt' => $now,
        ]);
        Capsule::table(Schema::SERVICES)->updateOrInsert(
            ['service_id' => $saved->serviceId],
            $saved->toRow(),
        );

        return $saved;
    }
}
