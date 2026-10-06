<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Model\AssignedAddon;
use SignNet\ResellerApi\Model\Assignment;
use SignNet\Whmcs\Provisioning\PlanDiff;

final class PlanDiffTest extends TestCase
{
    #[Test]
    public function aPortalWithoutAPackageGetsThePackageAndTheOrderedAddons(): void
    {
        $diff = PlanDiff::between(null, 'pkg-1', ['add-1' => 2, 'add-2' => 0]);

        self::assertFalse($diff->unassign);
        self::assertTrue($diff->assign);
        self::assertSame(['add-1' => 2], $diff->attach);
        self::assertSame([], $diff->detach);
    }

    #[Test]
    public function aPortalOnItsPlanNeedsNothing(): void
    {
        $diff = PlanDiff::between(self::assignment('pkg-1', [self::addon('add-1', 2)]), 'pkg-1', ['add-1' => 2]);

        self::assertTrue($diff->isEmpty());
    }

    #[Test]
    public function anAddonSetToZeroIsDetached(): void
    {
        $attached = self::addon('add-1', 2);

        $diff = PlanDiff::between(self::assignment('pkg-1', [$attached]), 'pkg-1', ['add-1' => 0]);

        self::assertSame([$attached], $diff->detach);
        self::assertSame([], $diff->attach);
        self::assertSame([], $diff->warnings);
    }

    #[Test]
    public function aNewQuantityReplacesTheAttachmentAndSaysSo(): void
    {
        $attached = self::addon('add-1', 2);

        $diff = PlanDiff::between(self::assignment('pkg-1', [$attached]), 'pkg-1', ['add-1' => 3]);

        self::assertSame([$attached], $diff->detach);
        self::assertSame(['add-1' => 3], $diff->attach);
        self::assertSame(
            ['Add-on SEATS5 goes from 2 to 3 by replacing its attachment.'],
            $diff->warnings,
            'Sign.net bills the larger of the two for the period, never both.',
        );
    }

    #[Test]
    public function aSwapWarnsAboutAddonsWhmcsDoesNotManage(): void
    {
        $diff = PlanDiff::between(self::assignment('pkg-1', [self::addon('add-9', 1, 'EXTRA')]), 'pkg-2', []);

        self::assertTrue($diff->unassign);
        self::assertTrue($diff->assign);
        self::assertCount(2, $diff->warnings);
        self::assertStringContainsString('Add-on EXTRA (x1) was attached in Sign.net', $diff->warnings[1]);
    }

    /**
     * @param list<AssignedAddon> $addons
     */
    private static function assignment(string $packageId, array $addons = []): Assignment
    {
        return new Assignment('asg-1', $packageId, strtoupper($packageId), 'Starter', 'USD', 0, $addons, []);
    }

    private static function addon(string $addonId, int $quantity, string $code = 'SEATS5'): AssignedAddon
    {
        return new AssignedAddon('sa-' . $addonId, $addonId, $code, $code, $quantity);
    }
}
