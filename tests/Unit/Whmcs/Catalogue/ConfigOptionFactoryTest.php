<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Catalogue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Model\Addon;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\Catalogue\CatalogueException;
use SignNet\Whmcs\Catalogue\ConfigOptionFactory;
use SignNet\Whmcs\Db\Rows;
use WHMCS\Database\Capsule;

final class ConfigOptionFactoryTest extends DatabaseTestCase
{
    private int $usdId;
    private int $starterId;
    private int $proId;

    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
        $this->usdId = WhmcsCatalogueSchema::insertCurrency('USD', true);
        $this->starterId = WhmcsCatalogueSchema::insertProduct('Starter portal', 'signnet');
        $this->proId = WhmcsCatalogueSchema::insertProduct('Pro portal', 'signnet');
    }

    #[Test]
    public function itCreatesAQuantityOptionForTheAddonInAGroupOfItsOwn(): void
    {
        $option = (new ConfigOptionFactory())->save(self::addon(), 50, [$this->starterId]);

        $group = Rows::first(Capsule::table('tblproductconfiggroups')->where('id', $option->groupId));
        $row = Rows::first(Capsule::table('tblproductconfigoptions')->where('id', $option->optionId));
        self::assertNotNull($group);
        self::assertNotNull($row);
        self::assertSame('Sign.net add-on: 5 seats', $group['name']);
        self::assertSame(
            ['addon_SEATS5|5 seats', '4', 0, 50],
            [$row['optionname'], (string) $row['optiontype'], (int) $row['qtyminimum'], (int) $row['qtymaximum']],
        );
        self::assertSame([$this->starterId], $option->linkedProductIds);
    }

    #[Test]
    public function itStoresTheAddonsNameHtmlEncodedAsWhmcsStoresText(): void
    {
        $addon = self::addon(['code' => 'seats5', 'name' => '<b>Seats</b> & "more" &amp;']);

        $option = (new ConfigOptionFactory())->save($addon, 50, []);

        $group = Rows::first(Capsule::table('tblproductconfiggroups')->where('id', $option->groupId));
        $row = Rows::first(Capsule::table('tblproductconfigoptions')->where('id', $option->optionId));
        $subOption = Rows::first(Capsule::table('tblproductconfigoptionssub')->where('configid', $option->optionId));
        $encoded = '&lt;b&gt;Seats&lt;/b&gt; &amp; &quot;more&quot; &amp;amp;';
        self::assertSame(
            ['Sign.net add-on: ' . $encoded, 'Sells Sign.net add-on SEATS5 by the unit.'],
            [$group['name'] ?? null, $group['description'] ?? null],
        );
        self::assertSame('addon_SEATS5|' . $encoded, $row['optionname'] ?? null);
        self::assertSame($encoded, $subOption['optionname'] ?? null);
        self::assertSame('Sign.net add-on: <b>Seats</b> & "more" &amp;', $option->groupName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codesAnOptionCannotCarry(): iterable
    {
        yield 'markup' => ['<b>SEATS</b>'];
        yield 'the separator' => ['SEATS|5'];
        yield 'a dot' => ['QA1.SEATS'];
        yield 'a space' => ['SEATS 5'];
        yield 'a letter beyond A to Z' => ['SITZPLÄTZE'];
    }

    #[Test]
    #[DataProvider('codesAnOptionCannotCarry')]
    public function itCreatesNoOptionForACodeTheModuleCouldNotReadBack(string $code): void
    {
        try {
            (new ConfigOptionFactory())->save(self::addon(['code' => $code]), 50, [$this->starterId]);
            self::fail('An option was created for a code the module cannot read back.');
        } catch (CatalogueException $refusal) {
            self::assertStringContainsString(
                'cannot be sold as a configurable option: the module only reads codes made of the letters A to Z, '
                . 'digits, hyphens and underscores.',
                $refusal->getMessage(),
            );
        }
        self::assertSame(0, Capsule::table('tblproductconfiggroups')->count());
        self::assertSame(0, Capsule::table('tblproductconfigoptions')->count());
    }

    #[Test]
    public function itStillSavesAnExistingOptionWhoseCodeItWouldNotCreate(): void
    {
        $groupId = Capsule::table('tblproductconfiggroups')->insertGetId(['name' => 'Extras', 'description' => '']);
        Capsule::table('tblproductconfigoptions')->insert([
            'gid' => $groupId,
            'optionname' => 'addon_QA1.SEATS|Extra seats',
            'optiontype' => '4',
            'qtyminimum' => 0,
            'qtymaximum' => 10,
            'order' => 0,
            'hidden' => 0,
        ]);

        $option = (new ConfigOptionFactory())->save(self::addon(['code' => 'QA1.SEATS']), 20, [$this->starterId]);

        self::assertSame([$groupId, 20, [$this->starterId]], [
            $option->groupId,
            $option->maxQuantity,
            $option->linkedProductIds,
        ]);
    }

    #[Test]
    public function itPricesOneUnitFromTheAddonInEveryCycle(): void
    {
        $option = (new ConfigOptionFactory())->save(self::addon(), 50, []);

        $subOption = Rows::first(Capsule::table('tblproductconfigoptionssub')->where('configid', $option->optionId));
        self::assertNotNull($subOption);
        $pricing = Rows::first(Capsule::table('tblpricing')->where('relid', $subOption['id']));
        self::assertNotNull($pricing);
        self::assertSame(['configoptions', $this->usdId], [$pricing['type'], (int) $pricing['currency']]);
        $columns = ['monthly', 'quarterly', 'annually', 'triennially', 'msetupfee'];
        self::assertEquals(
            [5.00, 15.00, 60.00, 180.00, 0.00],
            array_map(static fn (string $column): mixed => $pricing[$column], $columns),
        );
    }

    #[Test]
    public function itUpdatesTheOptionInsteadOfCreatingASecond(): void
    {
        $factory = new ConfigOptionFactory();
        $first = $factory->save(self::addon(), 50, [$this->starterId]);

        $second = $factory->save(self::addon(), 20, [$this->proId]);

        self::assertSame($first->optionId, $second->optionId);
        self::assertSame(20, $second->maxQuantity);
        self::assertSame([$this->proId], $second->linkedProductIds);
        self::assertSame(1, Capsule::table('tblproductconfiggroups')->count());
        self::assertSame(1, Capsule::table('tblproductconfigoptions')->count());
        self::assertSame(1, Capsule::table('tblpricing')->count());
    }

    #[Test]
    public function itLeavesLinksToProductsOfOtherModulesAlone(): void
    {
        $factory = new ConfigOptionFactory();
        $option = $factory->save(self::addon(), 50, [$this->starterId]);
        $hostingId = WhmcsCatalogueSchema::insertProduct('Web hosting', 'cpanel');
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $option->groupId, 'pid' => $hostingId]);

        $saved = $factory->save(self::addon(), 50, [$hostingId]);

        self::assertSame([$hostingId], $saved->linkedProductIds);
    }

    #[Test]
    public function itFindsAnOptionWhoseCodeWasTypedInLowerCase(): void
    {
        $groupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'name' => 'Extras &amp; more',
            'description' => '',
        ]);
        Capsule::table('tblproductconfigoptions')->insert([
            'gid' => $groupId,
            'optionname' => 'addon_seats5|Extra seats',
            'optiontype' => '4',
            'qtyminimum' => 0,
            'qtymaximum' => 10,
            'order' => 0,
            'hidden' => 0,
        ]);

        $option = (new ConfigOptionFactory())->find('SEATS5');

        self::assertNotNull($option);
        self::assertSame('Extras & more', $option->groupName, 'Read back as the administrator typed it.');
        self::assertNull((new ConfigOptionFactory())->find('SEATS50'));
    }

    #[Test]
    public function itWritesNothingForAnAddonInACurrencyWhmcsDoesNotHave(): void
    {
        try {
            (new ConfigOptionFactory())->save(self::addon(['currency' => 'EUR']), 50, [$this->starterId]);
            self::fail('An add-on priced in a currency WHMCS lacks was turned into an option.');
        } catch (CatalogueException $refusal) {
            self::assertStringContainsString('WHMCS has no EUR currency', $refusal->getMessage());
        }
        self::assertSame(0, Capsule::table('tblproductconfiggroups')->count());
        self::assertSame(0, Capsule::table('tblproductconfiglinks')->count());
    }

    /**
     * @param array<string, mixed> $summary Overrides of the add-on's catalogue row.
     */
    private static function addon(array $summary = []): Addon
    {
        $data = ApiFixtures::addon();
        $data['addon'] = $summary + (array) $data['addon'];

        return Addon::fromArray($data);
    }
}
