<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Model;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Model\AddonInput;
use SignNet\ResellerApi\Model\AddonItem;
use SignNet\ResellerApi\Model\AddonUpdate;
use SignNet\ResellerApi\Model\BillingCycle;
use SignNet\ResellerApi\Model\ItemCode;
use SignNet\ResellerApi\Model\PackageInput;
use SignNet\ResellerApi\Model\PackageItem;
use SignNet\ResellerApi\Model\PackageUpdate;

final class CatalogueInputTest extends TestCase
{
    #[Test]
    public function aPackageUpdateSendsNothingUntilAFieldIsSet(): void
    {
        self::assertSame([], (new PackageUpdate())->toArray());
        self::assertSame([], (new AddonUpdate())->toArray());
    }

    #[Test]
    public function aPackageUpdateIsImmutable(): void
    {
        $original = new PackageUpdate();

        $renamed = $original->withName('Pro');
        $changed = $renamed->withBasePriceMinor(2900)->withItems([new PackageItem(ItemCode::TEMPLATES, 25)]);

        self::assertSame([], $original->toArray());
        self::assertSame(['name' => 'Pro'], $renamed->toArray());
        self::assertSame(
            [
                'name' => 'Pro',
                'basePriceMinor' => 2900,
                'items' => [
                    [
                        'itemCode' => 'templates',
                        'includedQty' => 25,
                        'overageBundleSize' => 0,
                        'overageBundlePriceMinor' => 0,
                    ],
                ],
            ],
            $changed->toArray(),
        );
    }

    #[Test]
    public function anAddonUpdateCanClearTheDescriptionAndDeactivate(): void
    {
        $update = (new AddonUpdate())->withName('Seats')->withDescription(null)->withActive(false);

        self::assertSame(['name' => 'Seats', 'description' => null, 'isActive' => false], $update->toArray());
    }

    #[Test]
    public function aNewPackageOrAddonSendsItsCurrencyInUpperCase(): void
    {
        $package = new PackageInput('PRO', 'Pro', null, 'usd', BillingCycle::MONTHLY, 1900, []);
        $addon = new AddonInput('SEATS5', 'Seats', null, 'eur', 500, BillingCycle::MONTHLY, []);

        self::assertSame(['USD', 'EUR'], [$package->toArray()['currency'], $addon->toArray()['currency']]);
    }

    #[Test]
    public function aPackageInputRefusesItemsOfTheWrongKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (a caller without static analysis can pass anything) */
        new PackageInput('CODE', 'Name', null, 'USD', BillingCycle::MONTHLY, 0, [new AddonItem(ItemCode::SEATS, 1)]);
    }

    #[Test]
    public function anAddonInputRefusesABlankCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AddonInput(' ', 'Name', null, 'USD', 100, BillingCycle::ANNUAL, []);
    }

    #[Test]
    public function aPackageUpdateRefusesABlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PackageUpdate())->withName('');
    }
}
