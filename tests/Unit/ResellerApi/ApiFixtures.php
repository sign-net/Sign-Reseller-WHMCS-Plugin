<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

/**
 * The data of console answers, shaped as the backend sends them inside {"status": "OK", "data": ...}.
 */
final class ApiFixtures
{
    public const TENANT_ID = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    public const CREATED_AT = 1_758_000_000_000;

    /** The reseller's primary host, which addresses its console endpoints. */
    public const SLUG = 'reseller.sign.test';

    /**
     * GET /console/dashboard, as a key sees it.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(string $slug = self::SLUG): array
    {
        return [
            'totalDomains' => 1,
            'totalUsers' => 3,
            'domains' => [['domain' => 'sign.acme.test', 'companyName' => 'Acme Sign', 'userCount' => 3]],
            'isReseller' => true,
            'slug' => $slug,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function domainSetup(): array
    {
        return [
            'attached' => true,
            'verified' => false,
            'records' => [['type' => 'CNAME', 'name' => 'sign.acme.test', 'value' => 'cname.vercel-dns.com']],
            'note' => 'Point DNS at the hosting platform.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignment(): array
    {
        return [
            'id' => 'asg-1',
            'packageId' => 'pkg-1',
            'packageCode' => 'STARTER',
            'packageName' => 'Starter',
            'currency' => 'USD',
            'assignedAt' => self::CREATED_AT,
            'addons' => [
                [
                    'subscriptionAddonId' => 'sa-1',
                    'addonId' => 'add-1',
                    'code' => 'SEATS5',
                    'name' => '5 seats',
                    'quantity' => 2,
                ],
            ],
            'allocated' => ['documents' => 500, 'seats' => 20, 'templates' => 25, 'notarizations' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function privateLabel(): array
    {
        return [
            'tenantId' => self::TENANT_ID,
            'primaryHost' => 'sign.acme.test',
            'customDomain' => null,
            'createdAt' => self::CREATED_AT,
            'status' => 'Suspended',
            'suspendedAt' => self::CREATED_AT + 1000,
            'suspendedBy' => 'Reseller',
            'suspendReason' => 'Unpaid invoice',
            'owner' => [
                'userId' => 'u-owner',
                'email' => 'owner@acme.test',
                'firstName' => 'Ann',
                'lastName' => 'Lee',
            ],
            'memberCount' => 3,
            'assignment' => self::assignment(),
            'domainSetup' => self::domainSetup(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function suspensionState(): array
    {
        return [
            'tenantId' => self::TENANT_ID,
            'status' => 'Suspended',
            'suspendedAt' => self::CREATED_AT,
            'suspendedBy' => 'Platform',
            'suspendReason' => null,
        ];
    }

    /**
     * A row of a private label's members.
     *
     * @return array<string, mixed>
     */
    public static function user(): array
    {
        return [
            'id' => 'u-1',
            'firstName' => 'Bo',
            'lastName' => 'Chan',
            'email' => 'member@acme.test',
            'status' => 'Active',
            'role' => 'member',
            'isOwner' => false,
            'createdAt' => self::CREATED_AT,
        ];
    }

    /**
     * A row of the package catalogue's search.
     *
     * @return array<string, mixed>
     */
    public static function packageSummary(): array
    {
        return [
            'id' => 'pkg-1',
            'code' => 'STARTER',
            'name' => 'Starter',
            'currency' => 'USD',
            'billingCycle' => 'Monthly',
            'basePriceMinor' => 1900,
            'isActive' => true,
            'createdAt' => self::CREATED_AT,
            'updatedAt' => self::CREATED_AT + 5,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function package(): array
    {
        return [
            'billingPackage' => ['description' => 'For small teams'] + self::packageSummary(),
            'items' => [
                [
                    'id' => 'pi-1',
                    'packageId' => 'pkg-1',
                    'itemCode' => 'seats',
                    'includedQty' => 10,
                    'overageBundleSize' => 5,
                    'overageBundlePriceMinor' => 900,
                ],
            ],
            'assignedCount' => 4,
        ];
    }

    /**
     * A row of the add-on catalogue's search.
     *
     * @return array<string, mixed>
     */
    public static function addonSummary(): array
    {
        return [
            'id' => 'add-1',
            'code' => 'SEATS5',
            'name' => '5 seats',
            'currency' => 'USD',
            'priceMinor' => 500,
            'billingCycle' => 'Monthly',
            'isActive' => true,
            'createdAt' => self::CREATED_AT,
            'updatedAt' => self::CREATED_AT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function addon(): array
    {
        return [
            'addon' => ['description' => 'Five more seats'] + self::addonSummary(),
            'items' => [['id' => 'ai-1', 'addonId' => 'add-1', 'itemCode' => 'seats', 'grantedQty' => 5]],
            'areGrantsLocked' => true,
            'activeAttachments' => 2,
        ];
    }

    /**
     * One page of a catalogue search holding every row.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function page(string $listKey, array $rows): array
    {
        return [$listKey => $rows, 'total' => count($rows), 'page' => 1, 'pageSize' => 100];
    }

    /**
     * @return array<string, mixed>
     */
    public static function quotaWithPlan(): array
    {
        return [
            'subscription' => ['packageName' => 'Reseller Pro', 'billingCycle' => 'Monthly'],
            'window' => ['from' => self::CREATED_AT, 'to' => self::CREATED_AT + 2_592_000_000],
            'items' => [
                [
                    'itemCode' => 'seats',
                    'includedQty' => 50,
                    'allocatedNow' => 30,
                    'used' => 12,
                    'ownUsed' => 2,
                    'labelsBeyondAllocation' => 0,
                    'remaining' => 18,
                    'overAllocatedBy' => 0,
                ],
            ],
            'byPrivateLabel' => [
                [
                    'tenantId' => self::TENANT_ID,
                    'primaryHost' => 'sign.acme.test',
                    'allocatedInPeriod' => ['documents' => 500, 'seats' => 10, 'templates' => 25, 'notarizations' => 0],
                    'actual' => ['documents' => 640, 'seats' => 4, 'templates' => 2, 'notarizations' => 0],
                    'billed' => ['documents' => 640, 'seats' => 10, 'templates' => 25, 'notarizations' => 0],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function quotaWithoutPlan(): array
    {
        return ['subscription' => null, 'window' => null, 'items' => [], 'byPrivateLabel' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function usage(): array
    {
        return [
            'period' => '2026-07',
            'total' => ['documents' => 12, 'notarizations' => 0, 'points' => 3400, 'seats' => 7, 'templates' => 3],
            'privateLabels' => [
                [
                    'tenantId' => self::TENANT_ID,
                    'primaryHost' => 'sign.acme.test',
                    'metrics' => [
                        'documents' => 12,
                        'templates' => 3,
                        'seats' => 7,
                        'notarizations' => 0,
                        'points' => 3400,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $package
     *
     * @return array<string, mixed>
     */
    public static function provisionResult(?array $package = null): array
    {
        $result = ['tenantId' => self::TENANT_ID, 'ownerUserId' => 'u-owner', 'domainSetup' => self::domainSetup()];

        return $package === null ? $result : $result + ['package' => $package];
    }
}
