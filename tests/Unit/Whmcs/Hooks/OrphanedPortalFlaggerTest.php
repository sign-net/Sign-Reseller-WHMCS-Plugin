<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Hooks;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Hooks\OrphanedPortalFlagger;

final class OrphanedPortalFlaggerTest extends DatabaseTestCase
{
    #[Test]
    public function itWarnsThatAPortalOutlivedItsDeletedService(): void
    {
        $this->saveLink(ServiceLink::STATE_SUSPENDED);

        (new OrphanedPortalFlagger())->flag(['serviceid' => 101, 'userid' => 7, 'clientId' => 7]);

        $warning = 'WHMCS service #101 was deleted, but its Sign.net portal sign.acme.test (tenant tenant-1) is '
            . 'still suspended. Terminate it in Sign.net, or link it to another service.';
        self::assertSame([['message' => 'Sign.net: ' . $warning, 'userId' => 7]], WhmcsFake::$activity);
        $link = (new ServiceRepository())->find(101);
        self::assertNotNull($link);
        self::assertSame($warning, $link->lastError);
        self::assertSame('tenant-1', $link->tenantId);
    }

    #[Test]
    public function itSaysNothingWhenThePortalIsNotLive(): void
    {
        $this->saveLink(ServiceLink::STATE_TERMINATED);

        (new OrphanedPortalFlagger())->flag(['serviceid' => 101, 'userid' => 7]);
        (new OrphanedPortalFlagger())->flag(['serviceid' => 404, 'userid' => 7]);

        self::assertSame([], WhmcsFake::$activity);
        self::assertNull((new ServiceRepository())->find(101)?->lastError);
    }

    private function saveLink(string $state): void
    {
        (new ServiceRepository())->save(new ServiceLink(
            serviceId: 101,
            serverId: 1,
            tenantId: 'tenant-1',
            hostname: 'sign.acme.test',
            state: $state,
        ));
    }
}
