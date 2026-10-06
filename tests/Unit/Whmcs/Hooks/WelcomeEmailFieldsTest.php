<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Hooks;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Whmcs\Admin\EmailTemplateInstaller;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Hooks\WelcomeEmailFields;

final class WelcomeEmailFieldsTest extends DatabaseTestCase
{
    private const SERVICE_ID = 101;

    #[Test]
    public function itFillsTheWelcomeEmailWithThePortalAndItsDnsRecords(): void
    {
        $this->savePortal(['attached' => false, 'verified' => false, 'records' => [
            ['type' => 'CNAME', 'name' => 'sign.acme.test', 'value' => 'cname.vercel-dns.com'],
            ['type' => 'TXT', 'name' => '_vercel.acme.test', 'value' => 'vc-domain-verify=abc'],
            ['type' => 'A'],
        ]]);

        $fields = (new WelcomeEmailFields())->mergeFields(self::email());

        self::assertSame([
            'signnet_portal_url' => 'https://sign.acme.test',
            'signnet_portal_name' => 'Acme Sign',
            'signnet_portal_host' => 'sign.acme.test',
            'signnet_owner_email' => 'ada@acme.test',
            'signnet_dns_records' => "CNAME sign.acme.test cname.vercel-dns.com\n"
                . 'TXT _vercel.acme.test vc-domain-verify=abc',
            'signnet_domain_ready' => 'no',
        ], $fields);
    }

    #[Test]
    public function itSaysTheDomainIsReadyOnceSignNetAttachedIt(): void
    {
        $this->savePortal(['attached' => true, 'verified' => false, 'records' => []]);

        $fields = (new WelcomeEmailFields())->mergeFields(self::email());

        self::assertSame('yes', $fields['signnet_domain_ready']);
        self::assertSame('', $fields['signnet_dns_records']);
    }

    #[Test]
    public function itEscapesThePortalNameForTheHtmlEmail(): void
    {
        $this->savePortal(null, 'Tom & Jerry <Sign>');

        $fields = (new WelcomeEmailFields())->mergeFields(self::email());

        self::assertSame('Tom &amp; Jerry &lt;Sign&gt;', $fields['signnet_portal_name']);
        self::assertSame('no', $fields['signnet_domain_ready']);
    }

    #[Test]
    public function itShowsAnEntityTypedInThePortalNameAsTyped(): void
    {
        $this->savePortal(null, 'R&amp;D');

        $fields = (new WelcomeEmailFields())->mergeFields(self::email());

        self::assertSame('R&amp;amp;D', $fields['signnet_portal_name']);
    }

    #[Test]
    public function itLeavesOtherEmailsAlone(): void
    {
        $this->savePortal(null);
        $otherEmail = ['messagename' => 'Hosting Account Welcome Email'] + self::email();

        $fields = (new WelcomeEmailFields())->mergeFields($otherEmail);

        self::assertSame([], $fields);
    }

    #[Test]
    public function itLeavesServicesWithoutAPortalAlone(): void
    {
        (new ServiceRepository())->save(new ServiceLink(serviceId: self::SERVICE_ID, serverId: 1));

        self::assertSame([], (new WelcomeEmailFields())->mergeFields(self::email()));
        self::assertSame([], (new WelcomeEmailFields())->mergeFields(['relid' => 999] + self::email()));
    }

    /**
     * @param array<string, mixed>|null $domainSetup
     */
    private function savePortal(?array $domainSetup, string $portalName = 'Acme Sign'): void
    {
        (new ServiceRepository())->save(new ServiceLink(
            serviceId: self::SERVICE_ID,
            serverId: 1,
            tenantId: 'tenant-1',
            hostname: 'sign.acme.test',
            portalName: $portalName,
            ownerEmail: 'ada@acme.test',
            state: ServiceLink::STATE_ACTIVE,
            domainSetup: $domainSetup,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function email(): array
    {
        return ['messagename' => EmailTemplateInstaller::NAME, 'relid' => self::SERVICE_ID, 'mergefields' => []];
    }
}
