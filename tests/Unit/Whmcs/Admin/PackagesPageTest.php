<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\Admin\EmailTemplateInstaller;
use SignNet\Whmcs\Db\Rows;
use WHMCS\Database\Capsule;

final class PackagesPageTest extends AdminPageTestCase
{
    #[Test]
    public function itListsThePackagesAndHowMuchOfTheCapTheyUse(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::packageList([
            ApiFixtures::packageSummary(),
            ['id' => 'pkg-2', 'code' => 'OLD', 'isActive' => false] + ApiFixtures::packageSummary(),
        ]));

        $html = $this->get(['page' => 'packages']);

        self::assertStringContainsString('2 of 100 packages used, 1 of them archived.', $html);
        self::assertStringContainsString('<code>STARTER</code>', $html);
        self::assertStringContainsString('19.00 USD', $html);
        self::assertStringContainsString('label-default">Archived', $html);
        self::assertSame([...self::FIRST_CALLS, R::PACKAGES_CALL], $this->calls());
    }

    #[Test]
    public function itCreatesAPackageFromTheForm(): void
    {
        $this->queueToken();
        $this->transport->queueData(['id' => 'pkg-9']);
        $this->transport->queueData(R::packageList());

        $html = $this->post(['page' => 'packages', 'action' => 'create'], self::newPackage([
            'seats' => ['included' => '10', 'bundle_size' => '5', 'bundle_price' => '9.5'],
            'templates' => ['included' => '0', 'bundle_size' => '', 'bundle_price' => ''],
        ]));

        self::assertSame([
            'code' => 'pro',
            'name' => 'Pro & Co',
            'description' => null,
            'currency' => 'USD',
            'billingCycle' => 'Annual',
            'basePriceMinor' => 19000,
            'items' => [
                [
                    'itemCode' => 'seats',
                    'includedQty' => 10,
                    'overageBundleSize' => 5,
                    'overageBundlePriceMinor' => 950,
                ],
                [
                    'itemCode' => 'templates',
                    'includedQty' => 0,
                    'overageBundleSize' => 0,
                    'overageBundlePriceMinor' => 0,
                ],
            ],
        ], $this->bodySentTo(R::CREATE_PACKAGE_CALL));
        self::assertStringContainsString('Package PRO was created in Sign.net.', $html);
        self::assertSame([...self::FIRST_CALLS, R::CREATE_PACKAGE_CALL, R::PACKAGES_CALL], $this->calls());
    }

    #[Test]
    public function itSellsNotarisationsInAPackage(): void
    {
        $this->queueToken();
        $this->transport->queueData(['id' => 'pkg-9']);
        $this->transport->queueData(R::packageList());

        $this->post(['page' => 'packages', 'action' => 'create'], self::newPackage([
            'notarizations' => ['included' => '50', 'bundle_size' => '10', 'bundle_price' => '4'],
        ]));

        $notarisations = ['itemCode' => 'notarizations', 'includedQty' => 50, 'overageBundleSize' => 10];
        self::assertSame(
            [$notarisations + ['overageBundlePriceMinor' => 400]],
            $this->bodySentTo(R::CREATE_PACKAGE_CALL)['items'],
        );
    }

    #[Test]
    public function itShowsTheNotarisationsAPackageGivesAndWhatNoneMeans(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::package('pkg-1', ['seats' => 10, 'notarizations' => 50]));

        $html = $this->get(['page' => 'packages', 'action' => 'edit', 'id' => 'pkg-1']);

        self::assertStringContainsString('<td>Notarisations</td>', $html);
        self::assertStringContainsString('name="items[notarizations][included]" value="50"', $html);
        self::assertStringContainsString(
            'A portal given no notarisations notarises from your own pool instead, and you are billed what it uses.',
            $html,
        );
    }

    #[Test]
    public function itKeepsTheFormWhenSignNetRefusesThePackage(): void
    {
        $this->queueToken();
        $this->transport->queueError(400, 'code_in_use');

        $html = $this->post(['page' => 'packages', 'action' => 'create'], self::newPackage([
            'seats' => ['included' => '10', 'bundle_size' => '', 'bundle_price' => ''],
        ]));

        self::assertStringContainsString('That code is taken by one of your packages or add-ons', $html);
        self::assertStringContainsString('<h2>New package</h2>', $html);
        self::assertStringContainsString('value="Pro &amp; Co"', $html);
    }

    #[Test]
    public function itChecksTheNumbersBeforeAskingSignNet(): void
    {
        $html = $this->post(['page' => 'packages', 'action' => 'create'], self::newPackage([
            'seats' => ['included' => 'ten', 'bundle_size' => '', 'bundle_price' => ''],
        ]));

        self::assertStringContainsString('Included seats must be a whole number, 0 or more.', $html);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function itShowsWhatSignNetHoldsInTheEditForm(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::package());

        $html = $this->get(['page' => 'packages', 'action' => 'edit', 'id' => 'pkg-1']);

        self::assertStringContainsString('Edit package STARTER', $html);
        self::assertStringContainsString('<input type="hidden" name="code" value="STARTER">', $html);
        self::assertStringContainsString('name="items[seats][bundle_price]" value="9.00"', $html);
        self::assertStringContainsString('For small teams</textarea>', $html);
        self::assertStringContainsString('a change only reaches portals given the package afterwards', $html);
    }

    #[Test]
    public function itSendsOnlyWhatWasChanged(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::package());
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(R::packageList([ApiFixtures::packageSummary()]));

        $html = $this->post(['page' => 'packages', 'action' => 'edit', 'id' => 'pkg-1'], [
            'code' => 'STARTER',
            'name' => 'Starter Plus',
            'description' => 'For small teams',
            'base_price' => '19.00',
            'is_active' => '1',
            'items' => ['seats' => ['included' => '10', 'bundle_size' => '5', 'bundle_price' => '9.00']],
        ]);

        self::assertSame(['id' => 'pkg-1', 'name' => 'Starter Plus'], $this->bodySentTo(R::updatePackageCall()));
        self::assertStringContainsString('Package STARTER was saved.', $html);
    }

    #[Test]
    public function itKeepsAnItemTheFormDoesNotShowWhenTheItemsChange(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::packageWithAnUnknownItem());
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(R::packageList([ApiFixtures::packageSummary()]));

        $this->post(['page' => 'packages', 'action' => 'edit', 'id' => 'pkg-1'], [
            'name' => 'Starter',
            'description' => 'For small teams',
            'base_price' => '19.00',
            'is_active' => '1',
            'items' => ['seats' => ['included' => '12', 'bundle_size' => '5', 'bundle_price' => '9.00']],
        ]);

        $seats = ['itemCode' => 'seats', 'includedQty' => 12, 'overageBundleSize' => 5];
        $storage = ['itemCode' => 'storage', 'includedQty' => 50, 'overageBundleSize' => 0];
        self::assertSame(['id' => 'pkg-1', 'items' => [
            $seats + ['overageBundlePriceMinor' => 900],
            $storage + ['overageBundlePriceMinor' => 0],
        ]], $this->bodySentTo(R::updatePackageCall()));
    }

    #[Test]
    public function anItemTheFormDoesNotShowIsNotAChange(): void
    {
        $this->queueToken();
        $this->transport->queueData(self::packageWithAnUnknownItem());
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(R::packageList([ApiFixtures::packageSummary()]));

        $this->post(['page' => 'packages', 'action' => 'edit', 'id' => 'pkg-1'], [
            'name' => 'Starter Plus',
            'description' => 'For small teams',
            'base_price' => '19.00',
            'is_active' => '1',
            'items' => ['seats' => ['included' => '10', 'bundle_size' => '5', 'bundle_price' => '9.00']],
        ]);

        self::assertSame(['id' => 'pkg-1', 'name' => 'Starter Plus'], $this->bodySentTo(R::updatePackageCall()));
    }

    #[Test]
    public function itArchivesAPackage(): void
    {
        $this->queueToken();
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(R::packageList());

        $html = $this->post(['page' => 'packages', 'action' => 'archive', 'id' => 'pkg-1'], []);

        self::assertSame(['id' => 'pkg-1', 'isActive' => false], $this->bodySentTo(R::updatePackageCall()));
        self::assertStringContainsString('The package is archived.', $html);
    }

    #[Test]
    public function itRefusesAFormWithoutAValidToken(): void
    {
        WhmcsFake::$tokenValid = false;

        $html = $this->post(['page' => 'packages', 'action' => 'archive', 'id' => 'pkg-1'], []);

        self::assertStringContainsString('alert-danger', $html);
        self::assertStringContainsString('Invalid CSRF token.', $html);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function itOffersToCreateOrLinkAWhmcsProduct(): void
    {
        WhmcsCatalogueSchema::insertGroup('tblproductgroups', 'Portals');
        WhmcsCatalogueSchema::insertProduct('Legacy plan');
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::package());

        $html = $this->get(['page' => 'packages', 'action' => 'product', 'id' => 'pkg-1']);

        self::assertStringContainsString('Sell Starter (STARTER) in WHMCS', $html);
        self::assertStringContainsString('priced at 19.00 USD, monthly', $html);
        self::assertStringContainsString('<option value="1">Portals</option>', $html);
        self::assertStringContainsString('Legacy plan (#1)', $html);
    }

    #[Test]
    public function itCreatesAWhmcsProductForAPackage(): void
    {
        $groupId = WhmcsCatalogueSchema::insertGroup('tblproductgroups', 'Portals');
        WhmcsFake::$apiHandlers['AddProduct'] = static fn (): array => ['result' => 'success', 'pid' => 42];
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::package());

        $html = $this->post(['page' => 'packages', 'action' => 'product', 'id' => 'pkg-1'], [
            'group_id' => (string) $groupId,
            'name' => '',
            'server_group_id' => '',
            'over_allowance' => 'auto',
            'termination' => 'never',
        ]);

        $values = WhmcsFake::$apiCalls[0]['values'];
        self::assertSame(
            [$groupId, 'Starter', 'pkg-1', 'auto', 'never', EmailTemplateInstaller::findId()],
            [
                $values['gid'],
                $values['name'],
                $values['configoption1'],
                $values['configoption2'],
                $values['configoption3'],
                $values['welcomeemail'],
            ],
        );
        self::assertStringContainsString('<a href="configproducts.php?action=edit&amp;id=42">', $html);
        self::assertSame(2, Capsule::table('tblcustomfields')->where('relid', 42)->count());
    }

    #[Test]
    public function itLinksAnExistingProductToAPackage(): void
    {
        $productId = WhmcsCatalogueSchema::insertProduct('Legacy plan', 'cpanel');
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::package());

        $html = $this->post(
            ['page' => 'packages', 'action' => 'link', 'id' => 'pkg-1'],
            ['product_id' => (string) $productId],
        );

        $product = Rows::first(Capsule::table('tblproducts')->where('id', $productId));
        self::assertNotNull($product);
        self::assertSame(['signnet', 'pkg-1'], [$product['servertype'], $product['configoption1']]);
        self::assertStringContainsString('now sells package STARTER through Sign.net.', $html);
    }

    /**
     * The fixture package, also selling an item this plugin has no field for.
     *
     * @return array<string, mixed>
     */
    private static function packageWithAnUnknownItem(): array
    {
        $package = ApiFixtures::package();
        self::assertIsArray($package['items']);
        $package['items'][] = [
            'id' => 'pi-2',
            'packageId' => 'pkg-1',
            'itemCode' => 'storage',
            'includedQty' => 50,
            'overageBundleSize' => 0,
            'overageBundlePriceMinor' => 0,
        ];

        return $package;
    }

    /**
     * @param array<string, array<string, string>> $items
     *
     * @return array<string, mixed>
     */
    private static function newPackage(array $items): array
    {
        return [
            'code' => 'pro',
            'name' => 'Pro &amp; Co',
            'description' => '',
            'currency' => 'USD',
            'billing_cycle' => 'Annual',
            'base_price' => '190',
            'items' => $items + ['documents' => ['included' => '', 'bundle_size' => '', 'bundle_price' => '']],
        ];
    }
}
