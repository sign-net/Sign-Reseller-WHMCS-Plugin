<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Hooks;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Hooks\CartValidator;

final class CartValidatorTest extends DatabaseTestCase
{
    private int $portalProductId;
    private int $addressFieldId;

    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
        $this->portalProductId = WhmcsCatalogueSchema::insertProduct('Sign.net Starter', 'signnet');
        $this->addressFieldId = WhmcsCatalogueSchema::insertCustomField($this->portalProductId, 'Portal address');
        WhmcsCatalogueSchema::insertCustomField($this->portalProductId, 'Portal name');
    }

    #[Test]
    public function itAcceptsAnUnusedPortalAddress(): void
    {
        $errors = (new CartValidator())->validate([$this->portal('https://Sign.Acme.test/')]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function itRefusesAnAddressThatIsNotAHostnameNamingTheProduct(): void
    {
        $errors = (new CartValidator())->validate([$this->portal('acme')]);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Sign.net Starter: &quot;acme&quot; is not a valid portal address', $errors[0]);
    }

    #[Test]
    public function itRefusesTheSameAddressTwiceInTheCart(): void
    {
        $errors = (new CartValidator())->validate([
            $this->portal('sign.acme.test'),
            $this->portal('SIGN.acme.test'),
            $this->portal('sign.acme.test'),
        ]);

        self::assertSame(
            ['sign.acme.test is in your cart more than once. Each portal needs its own address.'],
            $errors,
        );
    }

    #[Test]
    public function itRefusesAnAddressAnotherServiceUsesInAnyState(): void
    {
        (new ServiceRepository())->save(new ServiceLink(
            serviceId: 55,
            serverId: 1,
            hostname: 'sign.acme.test',
            state: ServiceLink::STATE_TERMINATED,
        ));

        $errors = (new CartValidator())->validate([$this->portal('sign.acme.test')]);

        self::assertSame(['sign.acme.test is already in use. Choose another portal address.'], $errors);
    }

    #[Test]
    public function itIgnoresProductsOfOtherModules(): void
    {
        $hostingId = WhmcsCatalogueSchema::insertProduct('Web hosting', 'cpanel');

        $errors = (new CartValidator())->validate([['pid' => $hostingId, 'domain' => 'not a hostname']]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function itUsesTheDomainOfAProductWithoutAPortalAddressField(): void
    {
        $bareId = WhmcsCatalogueSchema::insertProduct('Sign.net Basic', 'signnet');

        $errors = (new CartValidator())->validate([
            ['pid' => $bareId, 'domain' => 'docs.acme.test'],
            ['pid' => (string) $bareId, 'domain' => ''],
        ]);

        self::assertSame(['Sign.net Basic: Enter the portal address, for example sign.example.com.'], $errors);
    }

    #[Test]
    public function itReadsAFieldNamedByItsKey(): void
    {
        $keyedId = WhmcsCatalogueSchema::insertProduct('Sign.net Pro', 'signnet');
        $fieldId = WhmcsCatalogueSchema::insertCustomField($keyedId, 'portal_host|Your portal address');

        $errors = (new CartValidator())->validate([
            ['pid' => $keyedId, 'domain' => 'ignored.acme.test', 'customfields' => [$fieldId => 'bad address']],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('bad address', $errors[0]);
    }

    #[Test]
    public function itEscapesWhatTheCustomerTypedWithoutEncodingItTwice(): void
    {
        $errors = (new CartValidator())->validate([
            $this->portal('<b>x</b>'),
            $this->portal('a&amp;b'),
        ]);

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $errors[0]);
        self::assertStringContainsString('a&amp;b', $errors[1]);
        self::assertStringNotContainsString('&amp;amp;', $errors[1]);
    }

    #[Test]
    public function itAcceptsACartWithNothingInIt(): void
    {
        self::assertSame([], (new CartValidator())->validate(null));
    }

    /**
     * @return array{pid: int, domain: string, customfields: array<int, string>}
     */
    private function portal(string $address): array
    {
        return ['pid' => $this->portalProductId, 'domain' => '', 'customfields' => [$this->addressFieldId => $address]];
    }
}
