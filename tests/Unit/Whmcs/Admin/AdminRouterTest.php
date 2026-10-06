<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\SignNetResponses;
use SignNet\Tests\Support\WhmcsSchema;
use SignNet\Whmcs\Admin\AdminRouter;
use WHMCS\Database\Capsule;

final class AdminRouterTest extends AdminPageTestCase
{
    #[Test]
    public function itAsksForASignNetServerFirst(): void
    {
        Capsule::table('tblservers')->delete();

        $html = $this->get(['page' => 'packages']);

        self::assertStringContainsString('<div class="alert alert-warning">Add a Sign.net server first', $html);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function itMarksTheCurrentTab(): void
    {
        $this->queueToken();
        $this->transport->queueData(['addons' => []]);

        $html = $this->get(['page' => 'addons']);

        self::assertStringContainsString(
            '<li class="active"><a href="addonmodules.php?module=signnet_reseller&amp;page=addons">Add-ons</a></li>',
            $html,
        );
        self::assertStringContainsString(
            '<li><a href="addonmodules.php?module=signnet_reseller&amp;page=dashboard">',
            $html,
        );
    }

    #[Test]
    public function itUsesTheDefaultServerSettingWhileThatServerIsActive(): void
    {
        $otherId = WhmcsSchema::insertServer(self::API_KEY, 'api.other.test');
        WhmcsSchema::setAddonSettings(['default_server' => (string) $otherId]);
        $this->queueToken();
        $this->transport->queueData(SignNetResponses::packageList());

        $this->get(['page' => 'packages']);

        self::assertSame(
            'https://api.other.test' . SignNetResponses::API . '/billing/packages/search',
            $this->transport->lastRequest()->url,
        );
    }

    #[Test]
    public function itFallsBackToTheFirstServerWhenTheDefaultIsDisabled(): void
    {
        $otherId = WhmcsSchema::insertServer(self::API_KEY, 'api.other.test');
        Capsule::table('tblservers')->where('id', $otherId)->update(['disabled' => 1]);
        WhmcsSchema::setAddonSettings(['default_server' => (string) $otherId]);
        $this->queueToken();
        $this->transport->queueData(SignNetResponses::packageList());

        $this->get(['page' => 'packages']);

        self::assertSame(
            'https://api.sign.test' . SignNetResponses::API . '/billing/packages/search',
            $this->transport->lastRequest()->url,
        );
    }

    #[Test]
    public function itShowsAFailureOnThePageRatherThanLettingItReachWhmcs(): void
    {
        Capsule::schema()->drop('tblcurrencies');

        $html = (new AdminRouter())->render([], ['page' => 'packages', 'action' => 'create'], [], 'GET');

        self::assertStringContainsString('<ul class="nav nav-tabs"', $html);
        self::assertStringContainsString('<div class="alert alert-danger">', $html);
        self::assertStringContainsString('tblcurrencies', $html);
    }
}
