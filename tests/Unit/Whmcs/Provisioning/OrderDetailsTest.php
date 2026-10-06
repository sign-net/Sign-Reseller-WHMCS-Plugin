<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\Whmcs\Provisioning\InvalidOrderException;
use SignNet\Whmcs\Provisioning\OrderDetails;
use SignNet\Whmcs\Provisioning\ProductSettings;

final class OrderDetailsTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function params(array $overrides = []): array
    {
        return array_replace([
            'domain' => '',
            'customfields' => ['Portal address' => 'https://Sign.Acme.test/', 'Portal name' => ' Acme Sign '],
            'configoptions' => [],
            'clientsdetails' => [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'email' => 'ada@acme.test',
                'companyname' => 'Acme Ltd',
            ],
        ], $overrides);
    }

    #[Test]
    public function itReadsThePortalFromTheOrdersCustomFields(): void
    {
        $order = OrderDetails::fromParams(self::params());

        self::assertSame('sign.acme.test', $order->hostname);
        self::assertSame('Acme Sign', $order->portalName);
        self::assertSame('ada@acme.test', $order->ownerEmail);
        self::assertSame('Ada', $order->ownerFirstName);
        self::assertSame('Lovelace', $order->ownerLastName);
        self::assertSame('https://sign.acme.test', $order->portalUrl());
    }

    #[Test]
    public function itFallsBackToTheDomainAndTheCompanyName(): void
    {
        $order = OrderDetails::fromParams(self::params(['customfields' => [], 'domain' => 'esign.acme.test']));

        self::assertSame('esign.acme.test', $order->hostname);
        self::assertSame('Acme Ltd', $order->portalName);
    }

    #[Test]
    public function itAcceptsTheShortFieldNames(): void
    {
        $order = OrderDetails::fromParams(self::params([
            'customfields' => ['portal_host' => 'docs.acme.test', 'portal_name' => 'Docs'],
        ]));

        self::assertSame('docs.acme.test', $order->hostname);
        self::assertSame('Docs', $order->portalName);
    }

    #[Test]
    public function itUsesTheClientsNameWithoutACompany(): void
    {
        $params = self::params(['customfields' => ['Portal address' => 'sign.acme.test']]);
        $params['clientsdetails']['companyname'] = '';

        self::assertSame('Ada Lovelace', OrderDetails::fromParams($params)->portalName);
    }

    #[Test]
    public function itDecodesWhatWhmcsStoredHtmlEncoded(): void
    {
        $params = self::params(['customfields' => [
            'Portal address' => 'sign.acme.test',
            'Portal name' => 'Tom &amp; Jerry',
        ]]);
        $params['clientsdetails']['lastname'] = 'O&#039;Brien';

        $order = OrderDetails::fromParams($params);

        self::assertSame('Tom & Jerry', $order->portalName);
        self::assertSame("O'Brien", $order->ownerLastName);
    }

    #[Test]
    public function itCapsOwnerNamesAtWhatSignNetAccepts(): void
    {
        $params = self::params();
        $params['clientsdetails']['firstname'] = str_repeat('é', 60);
        $params['clientsdetails']['lastname'] = str_repeat("\u{20000}", 30);

        $order = OrderDetails::fromParams($params);

        self::assertSame(str_repeat('é', 50), $order->ownerFirstName);
        self::assertSame(str_repeat("\u{20000}", 25), $order->ownerLastName, 'Sign.net counts these twice.');
    }

    #[Test]
    public function itCapsThePortalNameAtWhatSignNetStores(): void
    {
        $order = OrderDetails::fromParams(self::params(['customfields' => [
            'Portal address' => 'sign.acme.test',
            'Portal name' => str_repeat('n', 300),
        ]]));

        self::assertSame(str_repeat('n', 255), $order->portalName);
    }

    #[Test]
    public function itReadsAddOnQuantitiesFromTheirConfigurableOptions(): void
    {
        $order = OrderDetails::fromParams(self::params(['configoptions' => [
            'addon_seats5' => '3',
            'addon_DOCS500|500 extra documents' => 0,
            'Support level' => 'Gold',
            'addon_bad' => '-2',
        ]]));

        self::assertSame(['SEATS5' => 3, 'DOCS500' => 0, 'BAD' => 0], $order->addonQuantities);
    }

    #[Test]
    public function itRefusesAnOrderWithoutAValidOwnerEmail(): void
    {
        $params = self::params();
        $params['clientsdetails']['email'] = 'not-an-email';

        $this->expectException(InvalidOrderException::class);

        OrderDetails::fromParams($params);
    }

    #[Test]
    public function itRefusesAnOrderWithoutAPortalAddress(): void
    {
        $this->expectException(InvalidOrderException::class);

        OrderDetails::fromParams(self::params(['customfields' => [], 'domain' => '']));
    }

    #[Test]
    public function itReadsTheProductsModuleSettings(): void
    {
        $settings = ProductSettings::fromParams([
            'configoption1' => ' pkg-1 ',
            'configoption2' => 'auto',
            'configoption3' => 'never',
        ]);

        self::assertSame('pkg-1', $settings->packageId);
        self::assertTrue($settings->confirmsOverAllowance());
        self::assertSame(ProductSettings::TERMINATE_NEVER, $settings->termination);

        $labels = ProductSettings::fromParams([
            'configoption2' => 'Confirm automatically',
            'configoption3' => 'Always',
        ]);
        self::assertTrue($labels->confirmsOverAllowance());
        self::assertSame(ProductSettings::TERMINATE_ALWAYS, $labels->termination);

        self::assertSame(
            ['configoption1' => 'pkg-1', 'configoption2' => 'auto', 'configoption3' => 'never'],
            $settings->toConfigOptions(),
        );

        $defaults = ProductSettings::fromParams([]);
        self::assertNull($defaults->packageId);
        self::assertFalse($defaults->confirmsOverAllowance());
        self::assertSame(ProductSettings::TERMINATE_ON_CANCELLATION_REQUEST, $defaults->termination);
    }
}
