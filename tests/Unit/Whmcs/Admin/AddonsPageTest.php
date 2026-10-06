<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\SignNetResponses;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\Catalogue\ConfigOptionFactory;

final class AddonsPageTest extends AdminPageTestCase
{
    #[Test]
    public function itListsTheAddonsAndHowMuchOfTheCapTheyUse(): void
    {
        $this->queueToken();
        $this->transport->queueData(SignNetResponses::addonList());

        $html = $this->get(['page' => 'addons']);

        self::assertStringContainsString('1 of 100 add-ons used, 0 of them archived.', $html);
        self::assertStringContainsString('<code>SEATS5</code>', $html);
        self::assertStringContainsString('5.00 USD', $html);
        self::assertSame([...self::FIRST_CALLS, SignNetResponses::ADDONS_CALL], $this->calls());
    }

    #[Test]
    public function itCreatesAnAddonFromTheForm(): void
    {
        $this->queueToken();
        $this->transport->queueData(['id' => 'add-9']);
        $this->transport->queueData(ApiFixtures::page('addons', []));

        $html = $this->post(['page' => 'addons', 'action' => 'create'], [
            'code' => 'DOCS100',
            'name' => '100 documents',
            'description' => 'More documents',
            'currency' => 'USD',
            'billing_cycle' => 'Monthly',
            'price' => '15',
            'items' => ['documents' => ['granted' => '100'], 'seats' => ['granted' => '']],
        ]);

        self::assertSame([
            'code' => 'DOCS100',
            'name' => '100 documents',
            'description' => 'More documents',
            'currency' => 'USD',
            'priceMinor' => 1500,
            'billingCycle' => 'Monthly',
            'items' => [['itemCode' => 'documents', 'grantedQty' => 100]],
        ], $this->bodySentTo(SignNetResponses::CREATE_ADDON_CALL));
        self::assertStringContainsString('Add-on DOCS100 was created in Sign.net.', $html);
    }

    #[Test]
    public function itCreatesAnAddonThatGrantsNotarisations(): void
    {
        $this->queueToken();
        $this->transport->queueData(['id' => 'add-9']);
        $this->transport->queueData(ApiFixtures::page('addons', []));

        $this->post(['page' => 'addons', 'action' => 'create'], [
            'code' => 'NOTARY25',
            'name' => '25 notarisations',
            'description' => '',
            'currency' => 'USD',
            'billing_cycle' => 'Monthly',
            'price' => '10',
            'items' => ['notarizations' => ['granted' => '25']],
        ]);

        self::assertSame(
            [['itemCode' => 'notarizations', 'grantedQty' => 25]],
            $this->bodySentTo(SignNetResponses::CREATE_ADDON_CALL)['items'],
        );
    }

    #[Test]
    public function itExplainsWhyTheGrantsOfAnAttachedAddonCannotChange(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::addon());

        $html = $this->get(['page' => 'addons', 'action' => 'edit', 'id' => 'add-1']);

        self::assertStringContainsString('disabled name="items[seats][granted]" value="5"', $html);
        self::assertStringContainsString('what one unit grants can no longer change', $html);
        self::assertStringContainsString('name="price" value="5.00"', $html);
    }

    #[Test]
    public function itChangesOnlyThePriceOfAnAddonWhoseGrantsAreLocked(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::addon());
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(SignNetResponses::addonList());

        $this->post(['page' => 'addons', 'action' => 'edit', 'id' => 'add-1'], [
            'code' => 'SEATS5',
            'name' => '5 seats',
            'description' => 'Five more seats',
            'price' => '6.00',
            'is_active' => '1',
        ]);

        self::assertSame(
            ['id' => 'add-1', 'priceMinor' => 600],
            $this->bodySentTo(SignNetResponses::updateAddonCall()),
        );
    }

    #[Test]
    public function itChangesTheGrantsOfAnAddonNeverAttached(): void
    {
        $this->queueToken();
        $this->transport->queueData(['areGrantsLocked' => false, 'activeAttachments' => 0] + ApiFixtures::addon());
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(SignNetResponses::addonList());

        $this->post(['page' => 'addons', 'action' => 'edit', 'id' => 'add-1'], [
            'name' => '10 seats',
            'description' => 'Five more seats',
            'price' => '5.00',
            'is_active' => '1',
            'items' => ['seats' => ['granted' => '10']],
        ]);

        self::assertSame(
            ['id' => 'add-1', 'name' => '10 seats', 'items' => [['itemCode' => 'seats', 'grantedQty' => 10]]],
            $this->bodySentTo(SignNetResponses::updateAddonCall()),
        );
    }

    #[Test]
    public function itKeepsAGrantTheFormDoesNotShowWhenTheGrantsChange(): void
    {
        $this->queueToken();
        $this->transport->queueData([
            'items' => [
                ['id' => 'ai-1', 'addonId' => 'add-1', 'itemCode' => 'seats', 'grantedQty' => 5],
                ['id' => 'ai-2', 'addonId' => 'add-1', 'itemCode' => 'storage', 'grantedQty' => 20],
            ],
            'areGrantsLocked' => false,
            'activeAttachments' => 0,
        ] + ApiFixtures::addon());
        $this->transport->queueData(['message' => 'Success']);
        $this->transport->queueData(SignNetResponses::addonList());

        $this->post(['page' => 'addons', 'action' => 'edit', 'id' => 'add-1'], [
            'name' => '5 seats',
            'description' => 'Five more seats',
            'price' => '5.00',
            'is_active' => '1',
            'items' => ['seats' => ['granted' => '6']],
        ]);

        self::assertSame(['id' => 'add-1', 'items' => [
            ['itemCode' => 'seats', 'grantedQty' => 6],
            ['itemCode' => 'storage', 'grantedQty' => 20],
        ]], $this->bodySentTo(SignNetResponses::updateAddonCall()));
    }

    #[Test]
    public function itOffersTheConfigurableOptionOnSignNetProducts(): void
    {
        WhmcsCatalogueSchema::insertProduct('Starter portal', 'signnet');
        WhmcsCatalogueSchema::insertProduct('Web hosting', 'cpanel');
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::addon());

        $html = $this->get(['page' => 'addons', 'action' => 'option', 'id' => 'add-1']);

        self::assertStringContainsString('a Quantity option named &quot;addon_SEATS5|5 seats&quot;', $html);
        self::assertStringContainsString('name="max_quantity" value="100"', $html);
        self::assertStringContainsString('Starter portal (#1)', $html);
        self::assertStringNotContainsString('Web hosting', $html);
    }

    #[Test]
    public function itCreatesTheConfigurableOptionForAnAddon(): void
    {
        $productId = WhmcsCatalogueSchema::insertProduct('Starter portal', 'signnet');
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::addon());

        $html = $this->post(['page' => 'addons', 'action' => 'option', 'id' => 'add-1'], [
            'max_quantity' => '25',
            'products' => [(string) $productId],
        ]);

        $option = (new ConfigOptionFactory())->find('SEATS5');
        self::assertNotNull($option);
        self::assertSame([25, [$productId]], [$option->maxQuantity, $option->linkedProductIds]);
        self::assertStringContainsString(
            'offered on 1 product(s). <a href="configproductoptions.php?action=managegroup&amp;id=' . $option->groupId,
            $html,
        );
    }
}
