<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Hooks;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Whmcs\Admin\EmailTemplateInstaller;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use WHMCS\Database\Capsule;

/**
 * The addon's hooks.php, as WHMCS loads it: each hook reaches its class with what WHMCS provides.
 */
final class HooksFileTest extends DatabaseTestCase
{
    /**
     * What hooks.php registered; it is loaded once per process, and WhmcsFake forgets between tests.
     *
     * @var array<string, callable>
     */
    private static array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
        if (self::$hooks === []) {
            require_once dirname(__DIR__, 4) . '/modules/addons/signnet_reseller/hooks.php';
            foreach (WhmcsFake::$hooks as $hook) {
                self::$hooks[$hook['hook']] = $hook['callback'];
            }
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION['cart']);
        parent::tearDown();
    }

    #[Test]
    public function itRegistersTheCheckoutEmailAndDeletionHooks(): void
    {
        self::assertSame(['ShoppingCartValidateCheckout', 'EmailPreSend', 'ServiceDelete'], array_keys(self::$hooks));
    }

    #[Test]
    public function itValidatesTheCartWhmcsKeepsInTheSession(): void
    {
        $productId = WhmcsCatalogueSchema::insertProduct('Sign.net Starter', 'signnet');
        $_SESSION['cart']['products'] = [['pid' => $productId, 'domain' => 'acme']];

        $errors = self::$hooks['ShoppingCartValidateCheckout'](['a' => 'checkout', 'submit' => 'true']);

        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        self::assertStringStartsWith('Sign.net Starter: ', $errors[0]);
    }

    #[Test]
    public function itFillsTheWelcomeEmailsMergeFields(): void
    {
        $this->savePortal();

        $fields = self::$hooks['EmailPreSend'](['messagename' => EmailTemplateInstaller::NAME, 'relid' => 101]);

        self::assertIsArray($fields);
        self::assertSame('https://sign.acme.test', $fields['signnet_portal_url']);
    }

    #[Test]
    public function itFlagsAPortalWhoseServiceWasDeleted(): void
    {
        $this->savePortal();

        self::$hooks['ServiceDelete'](['serviceid' => 101, 'userid' => 7, 'clientId' => 7]);

        self::assertStringContainsString('is still active', (string) (new ServiceRepository())->find(101)?->lastError);
    }

    #[Test]
    public function itSkipsAHookThatFailsAndLogsWhy(): void
    {
        Capsule::schema()->drop(Schema::SERVICES);

        $fields = self::$hooks['EmailPreSend'](['messagename' => EmailTemplateInstaller::NAME, 'relid' => 101]);

        self::assertSame([], $fields);
        self::assertCount(1, WhmcsFake::activityMessages());
        self::assertStringStartsWith(
            'Sign.net: the EmailPreSend hook failed and was skipped: ',
            WhmcsFake::activityMessages()[0],
        );
    }

    private function savePortal(): void
    {
        (new ServiceRepository())->save(new ServiceLink(
            serviceId: 101,
            serverId: 1,
            tenantId: 'tenant-1',
            hostname: 'sign.acme.test',
            state: ServiceLink::STATE_ACTIVE,
        ));
    }
}
