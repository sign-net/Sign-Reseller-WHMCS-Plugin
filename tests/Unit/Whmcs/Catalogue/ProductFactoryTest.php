<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Catalogue;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Model\Package;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\Catalogue\CatalogueException;
use SignNet\Whmcs\Catalogue\ProductFactory;
use SignNet\Whmcs\Catalogue\ProductSpec;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Provisioning\ProductSettings;
use WHMCS\Database\Capsule;

final class ProductFactoryTest extends DatabaseTestCase
{
    private const NEW_PRODUCT_ID = 42;

    private int $usdId;

    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
        WhmcsCatalogueSchema::insertCurrency('EUR');
        $this->usdId = WhmcsCatalogueSchema::insertCurrency('USD', true);
        WhmcsFake::$apiHandlers['AddProduct'] = static fn (): array => [
            'result' => 'success',
            'pid' => self::NEW_PRODUCT_ID,
        ];
    }

    #[Test]
    public function itCreatesAProductThatSellsThePackageThroughTheModule(): void
    {
        $productId = (new ProductFactory())->create(self::package(), self::spec(serverGroupId: 3));

        self::assertSame(self::NEW_PRODUCT_ID, $productId);
        self::assertCount(1, WhmcsFake::$apiCalls);
        self::assertSame('AddProduct', WhmcsFake::$apiCalls[0]['command']);
        $values = WhmcsFake::$apiCalls[0]['values'];
        self::assertSame([
            'type' => 'other',
            'gid' => 5,
            'name' => 'Starter portal',
            'paytype' => 'recurring',
            'module' => 'signnet',
            'autosetup' => 'payment',
            'welcomeemail' => 9,
            'configoption1' => 'pkg-1',
            'configoption2' => 'auto',
            'configoption3' => 'never',
            'servergroupid' => 3,
        ], array_diff_key($values, ['pricing' => true]));
        self::assertIsArray($values['pricing']);
        self::assertSame([$this->usdId], array_keys($values['pricing']));
        self::assertSame('19.00', $values['pricing'][$this->usdId]['monthly']);
        self::assertSame('-1.00', $values['pricing'][$this->usdId]['annually']);
    }

    #[Test]
    public function itLetsWhmcsPickTheServerWithoutAServerGroup(): void
    {
        (new ProductFactory())->create(self::package(), self::spec(serverGroupId: null));

        self::assertArrayNotHasKey('servergroupid', WhmcsFake::$apiCalls[0]['values']);
    }

    #[Test]
    public function itGivesTheNewProductThePortalOrderFields(): void
    {
        (new ProductFactory())->create(self::package(), self::spec());

        $fields = Rows::all(Capsule::table('tblcustomfields')->where('relid', self::NEW_PRODUCT_ID)->orderBy('id'));
        self::assertCount(2, $fields);
        self::assertSame(
            ['Portal address', 'text', 'on', 'on', 'Where your portal will live, e.g. sign.example.com'],
            [
                $fields[0]['fieldname'],
                $fields[0]['fieldtype'],
                $fields[0]['required'],
                $fields[0]['showorder'],
                $fields[0]['description'],
            ],
        );
        self::assertSame(
            ['Portal name', '', 'on'],
            [$fields[1]['fieldname'], $fields[1]['required'], $fields[1]['showorder']],
        );
        self::assertSame('product', $fields[1]['type']);
    }

    #[Test]
    public function itRefusesAPackageInACurrencyWhmcsDoesNotHave(): void
    {
        $data = ApiFixtures::package();
        $data['billingPackage'] = ['currency' => 'GBP'] + (array) $data['billingPackage'];
        $package = Package::fromArray($data);

        try {
            (new ProductFactory())->create($package, self::spec());
            self::fail('A package priced in a currency WHMCS lacks was turned into a product.');
        } catch (CatalogueException $refusal) {
            self::assertStringContainsString('WHMCS has no GBP currency', $refusal->getMessage());
        }
        self::assertSame([], WhmcsFake::$apiCalls);
    }

    #[Test]
    public function itSaysWhyWhmcsRefusedTheProduct(): void
    {
        WhmcsFake::$apiHandlers['AddProduct'] = static fn (): array => [
            'result' => 'error',
            'message' => 'You must supply a valid Product Group ID',
        ];

        try {
            (new ProductFactory())->create(self::package(), self::spec());
            self::fail('A refused product was reported as created.');
        } catch (CatalogueException $refusal) {
            self::assertStringContainsString('You must supply a valid Product Group ID', $refusal->getMessage());
        }
        self::assertSame(0, Capsule::table('tblcustomfields')->count());
    }

    #[Test]
    public function itPointsAnExistingProductAtThePackageKeepingItsModuleSettings(): void
    {
        $productId = WhmcsCatalogueSchema::insertProduct('Old plan', 'signnet', [
            'configoption1' => 'pkg-old',
            'configoption2' => 'auto',
            'configoption3' => 'always',
        ]);

        (new ProductFactory())->link($productId, 'pkg-1');

        $product = Rows::first(Capsule::table('tblproducts')->where('id', $productId));
        self::assertNotNull($product);
        self::assertSame(
            ['signnet', 'pkg-1', 'auto', 'always'],
            [$product['servertype'], $product['configoption1'], $product['configoption2'], $product['configoption3']],
        );
    }

    #[Test]
    public function itDefaultsSettingsLeftByAnotherModule(): void
    {
        $productId = WhmcsCatalogueSchema::insertProduct('Hosting', 'cpanel', [
            'configoption2' => 'Unlimited',
            'configoption3' => 'yes',
        ]);

        (new ProductFactory())->link($productId, 'pkg-1');

        $product = Rows::first(Capsule::table('tblproducts')->where('id', $productId));
        self::assertNotNull($product);
        self::assertSame(['hold', 'cancel_requests'], [$product['configoption2'], $product['configoption3']]);
    }

    #[Test]
    public function itAddsOnlyTheOrderFieldsALinkedProductLacks(): void
    {
        $productId = WhmcsCatalogueSchema::insertProduct('Old plan');
        WhmcsCatalogueSchema::insertCustomField($productId, 'portal_host|Your portal address');

        (new ProductFactory())->link($productId, 'pkg-1');

        $names = Capsule::table('tblcustomfields')->where('relid', $productId)->orderBy('id')->pluck('fieldname');
        self::assertSame(['portal_host|Your portal address', 'Portal name'], $names->all());
    }

    #[Test]
    public function itRefusesToLinkAProductThatDoesNotExist(): void
    {
        $this->expectException(CatalogueException::class);
        $this->expectExceptionMessage('WHMCS has no product #77.');

        (new ProductFactory())->link(77, 'pkg-1');
    }

    private static function package(): Package
    {
        return Package::fromArray(ApiFixtures::package());
    }

    private static function spec(?int $serverGroupId = null): ProductSpec
    {
        return new ProductSpec(
            5,
            'Starter portal',
            $serverGroupId,
            ProductSettings::OVER_ALLOWANCE_AUTO,
            ProductSettings::TERMINATE_NEVER,
            9,
        );
    }
}
