<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Model\PrivateLabelSummary;
use SignNet\ResellerApi\Model\TenantStatus;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Whmcs\Admin\Dashboard\AttentionItem;
use SignNet\Whmcs\Admin\Dashboard\AttentionList;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use WHMCS\Database\Capsule;

final class AttentionListTest extends DatabaseTestCase
{
    private const CLIENT_ID = 7;

    #[Test]
    public function itListsOrdersHeldOverTheAllowanceWithTheirWarnings(): void
    {
        $this->service(104, 'Pending', [
            'packageState' => ServiceLink::PACKAGE_HELD,
            'heldWarnings' => ['Seats: 10 requested, 4 left.'],
        ]);

        $items = (new AttentionList())->build([], []);

        self::assertSame(['#104 Order held: it would go over your Sign.net allowance'], self::summaries($items));
        self::assertSame('Seats: 10 requested, 4 left.', $items[0]->details[0]);
        self::assertSame('clientsservices.php?userid=7&id=104', $items[0]->serviceUrl());
    }

    #[Test]
    public function itListsProvisioningThatFailedOrIsInDoubt(): void
    {
        $this->service(105, 'Pending', [
            'attemptState' => ServiceLink::ATTEMPT_FAILED,
            'lastError' => 'That portal address is already in use on Sign.net.',
        ]);
        $this->service(106, 'Pending', ['attemptState' => ServiceLink::ATTEMPT_UNKNOWN]);

        $items = (new AttentionList())->build([], []);

        self::assertSame(['#105 Provisioning failed', '#106 Provisioning in doubt'], self::summaries($items));
        self::assertSame(['That portal address is already in use on Sign.net.'], $items[0]->details);
    }

    #[Test]
    public function itListsPortalsWhoseAddressIsNotAttachedYet(): void
    {
        $this->service(107, 'Active', self::live('t-107', ['domainSetup' => ['attached' => false, 'records' => []]]));

        $items = (new AttentionList())->build([self::label('t-107', TenantStatus::ACTIVE)], []);

        self::assertSame(['#107 Portal address not attached yet'], self::summaries($items));
    }

    #[Test]
    public function itListsPortalsWhmcsAndSignNetDisagreeAbout(): void
    {
        $this->service(101, 'Active', self::live('t-101'));
        $this->service(102, 'Suspended', self::live('t-102', ['state' => ServiceLink::STATE_SUSPENDED]));
        $this->service(103, 'Active', self::live('t-103'));

        $items = (new AttentionList())->build([
            self::label('t-101', TenantStatus::SUSPENDED),
            self::label('t-102', TenantStatus::ACTIVE),
        ], []);

        self::assertSame([
            '#101 Suspended in Sign.net, Active in WHMCS',
            '#102 Suspended in WHMCS, active in Sign.net',
            '#103 Portal missing from Sign.net',
        ], self::summaries($items));
    }

    #[Test]
    public function itListsLivePortalsWhoseServiceWhmcsHasEnded(): void
    {
        $this->service(112, 'Cancelled', self::live('t-112'));
        $this->service(113, 'Terminated', self::live('t-113', ['state' => ServiceLink::STATE_SUSPENDED]));
        $this->service(114, 'Fraud', self::live('t-114'));

        $items = (new AttentionList())->build([
            self::label('t-112', TenantStatus::ACTIVE),
            self::label('t-113', TenantStatus::SUSPENDED),
            self::label('t-114', TenantStatus::ACTIVE),
        ], []);

        self::assertSame([
            '#112 Cancelled in WHMCS, live in Sign.net',
            '#113 Terminated in WHMCS, live in Sign.net',
            '#114 Fraud in WHMCS, live in Sign.net',
        ], self::summaries($items));
        self::assertSame(
            ['The portal was not deleted, so you are still billed for its package, and its users can sign in unless '
                . 'it is suspended. Run Terminate on the service to delete it.'],
            $items[0]->details,
        );
        self::assertSame('clientsservices.php?userid=7&id=114', $items[2]->serviceUrl());
    }

    #[Test]
    public function itListsSuspendedServicesWhosePortalWasSuspendedOutsideWhmcs(): void
    {
        $outside = ['state' => ServiceLink::STATE_SUSPENDED, 'suspendedOutsideAt' => 1_900_000_000];
        $this->service(115, 'Suspended', self::live('t-115', $outside));
        $this->service(116, 'Active', self::live('t-116', $outside));
        $this->service(117, 'Suspended', self::live('t-117', ['state' => ServiceLink::STATE_SUSPENDED]));

        $items = (new AttentionList())->build([
            self::label('t-115', TenantStatus::SUSPENDED),
            self::label('t-116', TenantStatus::SUSPENDED),
            self::label('t-117', TenantStatus::SUSPENDED),
        ], []);

        self::assertSame([
            '#115 Suspended outside WHMCS',
            '#116 Suspended in Sign.net, Active in WHMCS',
        ], self::summaries($items));
        self::assertSame(
            ['The portal was suspended outside WHMCS, so WHMCS does not lift that suspension when the client pays. '
                . 'Lift it in the Sign.net console, or unsuspend the service from its page in WHMCS.'],
            $items[0]->details,
        );
    }

    #[Test]
    public function itListsLivePortalsWhoseServiceWasDeleted(): void
    {
        $this->service(108, null, self::live('t-108', ['lastError' => 'WHMCS service #108 was deleted, but ...']));
        $this->service(109, null, self::live('t-109'));

        $items = (new AttentionList())->build([self::label('t-108', TenantStatus::ACTIVE)], []);

        self::assertSame(['#108 WHMCS service deleted, portal still live'], self::summaries($items));
        self::assertSame(['WHMCS service #108 was deleted, but ...'], $items[0]->details);
        self::assertNull($items[0]->serviceUrl());
    }

    #[Test]
    public function itSkipsTheComparisonsWithSignNetWhenItsListIsUnavailable(): void
    {
        $this->service(101, 'Active', self::live('t-101'));
        $this->service(109, null, self::live('t-109'));

        $items = (new AttentionList())->build(null, []);

        self::assertSame(['#109 WHMCS service deleted, portal still live'], self::summaries($items));
    }

    #[Test]
    public function itLeavesHealthyAndFinishedPortalsOut(): void
    {
        $this->service(110, 'Active', self::live('t-110'));
        $this->service(111, 'Terminated', [
            'tenantId' => 't-111',
            'state' => ServiceLink::STATE_TERMINATED,
            'attemptState' => ServiceLink::ATTEMPT_FAILED,
        ]);

        self::assertSame([], (new AttentionList())->build([self::label('t-110', TenantStatus::ACTIVE)], []));
    }

    #[Test]
    public function itListsSettingsNewPortalsIgnore(): void
    {
        $items = (new AttentionList())->build([], ['support_url', 'color_primary']);

        self::assertSame([
            '#- Addon setting "Portal support URL" is not valid',
            '#- Addon setting "Primary colour" is not valid',
        ], self::summaries($items));
        self::assertStringContainsString('a web address', $items[0]->details[0]);
        self::assertStringContainsString('a colour', $items[1]->details[0]);
    }

    /**
     * @param array<string, mixed> $link ServiceLink properties.
     */
    private function service(int $serviceId, ?string $whmcsStatus, array $link): void
    {
        if ($whmcsStatus !== null) {
            Capsule::table('tblhosting')->insert([
                'id' => $serviceId,
                'userid' => self::CLIENT_ID,
                'domainstatus' => $whmcsStatus,
            ]);
        }
        (new ServiceRepository())->save((new ServiceLink($serviceId, 1))->with($link + [
            'hostname' => 'portal' . $serviceId . '.acme.test',
        ]));
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private static function live(string $tenantId, array $more = []): array
    {
        return $more + ['tenantId' => $tenantId, 'state' => ServiceLink::STATE_ACTIVE];
    }

    private static function label(string $tenantId, string $status): PrivateLabelSummary
    {
        return new PrivateLabelSummary($tenantId, $tenantId . '.acme.test', $status);
    }

    /**
     * @param list<AttentionItem> $items
     *
     * @return list<string>
     */
    private static function summaries(array $items): array
    {
        return array_map(
            static fn (AttentionItem $item): string => '#' . ($item->serviceId ?? '-') . ' ' . $item->problem,
            $items,
        );
    }
}
