<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Admin;

use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Model\TenantStatus;
use SignNet\Tests\Support\SignNetResponses;
use SignNet\Tests\Unit\ResellerApi\ApiFixtures;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;

final class DashboardPageTest extends AdminPageTestCase
{
    #[Test]
    public function itShowsThePlanAndFlagsItemsRunningLowOrOverAllocated(): void
    {
        $this->queueToken();
        $this->transport->queueData(['items' => [
            self::quotaItem('documents', included: 1000, allocated: 950, remaining: 45, ownUsed: 3, labelsBeyond: 2),
            self::quotaItem('seats', included: 50, allocated: 60, remaining: -10, overAllocated: 10),
            self::quotaItem('notarizations', included: 200, allocated: 20, remaining: 180),
        ]] + ApiFixtures::quotaWithPlan());
        $this->transport->queueData(SignNetResponses::labels());

        $html = $this->get(['page' => 'dashboard']);

        self::assertStringContainsString(
            'Plan: Reseller Pro (Monthly). Billing window: 2025-09-16 to 2025-10-16 (UTC).',
            $html,
        );
        self::assertStringContainsString('Low: less than 10% left', $html);
        self::assertStringContainsString('Over-allocated by 10', $html);
        self::assertStringContainsString('<td>Documents</td><td>1000</td><td>950</td><td>5</td><td>45</td>', $html);
        self::assertStringContainsString('<td>Notarisations</td><td>200</td><td>20</td><td>0</td><td>180</td>', $html);
        self::assertStringContainsString('Used outside packages is what your own team used', $html);
        self::assertStringContainsString('Not in your plan', $html);
        self::assertStringContainsString('Nothing needs your attention.', $html);
        self::assertSame(
            [...self::FIRST_CALLS, SignNetResponses::QUOTA_CALL, 'GET ' . SignNetResponses::LABELS],
            $this->calls(),
        );
    }

    #[Test]
    public function itShowsTheKeysEnvironmentAndScopesButNeverTheKey(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());
        $this->transport->queueData(SignNetResponses::labels());

        $html = $this->get([]);

        self::assertStringContainsString('Environment: Test', $html);
        self::assertSame(3, substr_count($html, 'label-success">Granted'));
        self::assertStringNotContainsString(self::API_SECRET, $html);
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $html);
    }

    #[Test]
    public function itFlagsScopesTheKeyLacks(): void
    {
        $this->transport->queueJson(200, [
            'access_token' => self::ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'reseller:read',
        ]);
        $this->transport->queueData(SignNetResponses::dashboard());
        $this->transport->queueData(ApiFixtures::quotaWithPlan());
        $this->transport->queueData(SignNetResponses::labels());

        $html = $this->get([]);

        self::assertSame(2, substr_count($html, 'label-danger">Missing'));
        self::assertStringContainsString('The key lacks reseller:provision, reseller:packages.', $html);
    }

    #[Test]
    public function itSaysWhenSignNetHasNotPutTheResellerOnAPlan(): void
    {
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithoutPlan());
        $this->transport->queueData(SignNetResponses::labels());

        $html = $this->get([]);

        self::assertStringContainsString(
            'Sign.net has not put your account on a plan yet — packages cannot be assigned.',
            $html,
        );
    }

    #[Test]
    public function itListsWhatNeedsAttentionWithLinksToTheServices(): void
    {
        $this->insertService(self::SERVICE_ID, 'Active');
        (new ServiceRepository())->save(new ServiceLink(
            serviceId: self::SERVICE_ID,
            serverId: $this->serverId,
            tenantId: SignNetResponses::TENANT_ID,
            hostname: SignNetResponses::HOST,
            state: ServiceLink::STATE_ACTIVE,
        ));
        $this->queueToken();
        $this->transport->queueData(ApiFixtures::quotaWithPlan());
        $this->transport->queueData(SignNetResponses::labels(
            [SignNetResponses::TENANT_ID => SignNetResponses::HOST],
            TenantStatus::SUSPENDED,
        ));

        $html = $this->get([]);

        self::assertStringContainsString('<a href="clientsservices.php?userid=7&amp;id=101">#101</a>', $html);
        self::assertStringContainsString('Suspended in Sign.net, Active in WHMCS', $html);
    }

    #[Test]
    public function itStillShowsWhatWhmcsKnowsWhenSignNetRefusesTheKey(): void
    {
        $this->insertService(self::SERVICE_ID);
        (new ServiceRepository())->save(new ServiceLink(
            serviceId: self::SERVICE_ID,
            serverId: $this->serverId,
            packageState: ServiceLink::PACKAGE_HELD,
        ));
        $this->transport->queueJson(401, ['error' => 'invalid_client']);

        $html = $this->get([]);

        self::assertStringContainsString('Sign.net refused the API key.', $html);
        self::assertStringContainsString('Environment: Test', $html);
        self::assertStringContainsString('Portals were not compared with Sign.net', $html);
        self::assertStringContainsString('Order held: it would go over your Sign.net allowance', $html);
        self::assertSame([SignNetResponses::TOKEN_CALL], $this->calls());
    }

    /**
     * @return array<string, int|string>
     */
    private static function quotaItem(
        string $itemCode,
        int $included,
        int $allocated,
        int $remaining,
        int $overAllocated = 0,
        int $ownUsed = 0,
        int $labelsBeyond = 0,
    ): array {
        return [
            'itemCode' => $itemCode,
            'includedQty' => $included,
            'allocatedNow' => $allocated,
            'used' => 12,
            'ownUsed' => $ownUsed,
            'labelsBeyondAllocation' => $labelsBeyond,
            'remaining' => $remaining,
            'overAllocatedBy' => $overAllocated,
        ];
    }
}
