<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

use SignNet\Tests\Unit\ResellerApi\ApiFixtures;

/**
 * Sign.net answers for module tests, shaped as the console sends them inside its envelope (queue
 * them with FakeTransport::queueData()), and the calls they answer.
 */
final class SignNetResponses
{
    public const TENANT_ID = ApiFixtures::TENANT_ID;
    public const HOST = 'sign.acme.test';
    public const SLUG = ApiFixtures::SLUG;

    /** Request paths, and calls named "METHOD /path" as ModuleTestCase::calls() lists them. */
    public const TOKEN_PATH = '/api/v1/auth/token';
    public const API = '/console/' . self::SLUG . '/reseller';
    public const LABELS = self::API . '/private-labels';
    public const LABEL = self::LABELS . '/' . self::TENANT_ID;
    public const TOKEN_CALL = 'POST ' . self::TOKEN_PATH;
    public const DASHBOARD_CALL = 'GET /console/dashboard';
    public const QUOTA_CALL = 'GET ' . self::API . '/billing/quota';
    public const PACKAGES_CALL = 'POST ' . self::API . '/billing/packages/search';
    public const CREATE_PACKAGE_CALL = 'POST ' . self::API . '/billing/packages';
    public const ADDONS_CALL = 'POST ' . self::API . '/billing/addons/search';
    public const CREATE_ADDON_CALL = 'POST ' . self::API . '/billing/addons';
    public const PROVISION_CALL = 'POST ' . self::LABELS;

    public static function packageCall(): string
    {
        return 'POST ' . self::API . '/billing/packages/get';
    }

    public static function updatePackageCall(): string
    {
        return 'POST ' . self::API . '/billing/packages/update';
    }

    public static function addonCall(): string
    {
        return 'POST ' . self::API . '/billing/addons/get';
    }

    public static function updateAddonCall(): string
    {
        return 'POST ' . self::API . '/billing/addons/update';
    }

    /**
     * GET /console/dashboard, which tells a key its reseller's slug.
     *
     * @return array<string, mixed>
     */
    public static function dashboard(): array
    {
        return ApiFixtures::dashboard();
    }

    /**
     * @param array<string, int> $remaining Item code => what is left of the allowance.
     *
     * @return array<string, mixed>
     */
    public static function quota(
        array $remaining = ['documents' => 1000, 'seats' => 100, 'templates' => 100, 'notarizations' => 100],
    ): array {
        $items = [];
        foreach ($remaining as $itemCode => $left) {
            $items[] = [
                'itemCode' => $itemCode,
                'includedQty' => 1000,
                'allocatedNow' => 1000 - $left,
                'used' => 0,
                'ownUsed' => 0,
                'labelsBeyondAllocation' => 0,
                'remaining' => $left,
                'overAllocatedBy' => 0,
            ];
        }

        return ['items' => $items] + ApiFixtures::quotaWithPlan();
    }

    /**
     * @param array<string, int> $included Item code => included quantity.
     *
     * @return array<string, mixed>
     */
    public static function package(
        string $packageId = 'pkg-1',
        array $included = ['seats' => 10],
        bool $active = true,
    ): array {
        $items = [];
        foreach ($included as $itemCode => $quantity) {
            $items[] = [
                'id' => 'pi-' . $itemCode,
                'packageId' => $packageId,
                'itemCode' => $itemCode,
                'includedQty' => $quantity,
                'overageBundleSize' => 0,
                'overageBundlePriceMinor' => 0,
            ];
        }
        $package = ApiFixtures::package();
        $summary = ['id' => $packageId, 'code' => strtoupper($packageId), 'isActive' => $active];

        return ['billingPackage' => $summary + (array) $package['billingPackage'], 'items' => $items] + $package;
    }

    /**
     * The package catalogue's search, holding $rows.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function packageList(array $rows = []): array
    {
        return ApiFixtures::page('packages', $rows);
    }

    /**
     * The add-on catalogue's search, holding one add-on.
     *
     * @return array<string, mixed>
     */
    public static function addonList(string $addonId = 'add-1', string $code = 'SEATS5', bool $active = true): array
    {
        return ApiFixtures::page(
            'addons',
            [['id' => $addonId, 'code' => $code, 'isActive' => $active] + ApiFixtures::addonSummary()],
        );
    }

    /**
     * @param array<string, int> $granted Item code => quantity granted per unit.
     *
     * @return array<string, mixed>
     */
    public static function addon(
        string $addonId = 'add-1',
        string $code = 'SEATS5',
        array $granted = ['seats' => 5],
    ): array {
        $items = [];
        foreach ($granted as $itemCode => $quantity) {
            $items[] = [
                'id' => 'ai-' . $itemCode,
                'addonId' => $addonId,
                'itemCode' => $itemCode,
                'grantedQty' => $quantity,
            ];
        }
        $addon = ApiFixtures::addon();

        return ['addon' => ['id' => $addonId, 'code' => $code] + (array) $addon['addon'], 'items' => $items] + $addon;
    }

    /**
     * @param array<string, mixed>|null $package The provisioning result's package outcome.
     *
     * @return array<string, mixed>
     */
    public static function provisioned(?array $package = null): array
    {
        return ApiFixtures::provisionResult($package);
    }

    /**
     * @return array<string, mixed>
     */
    public static function assigned(string $assignmentId = 'asg-1'): array
    {
        return ['outcome' => 'Assigned', 'assignmentId' => $assignmentId, 'allocated' => ['seats' => 10]];
    }

    /**
     * @param list<array<string, mixed>> $warnings
     *
     * @return array<string, mixed>
     */
    public static function confirmationRequired(string $key = 'confirm-1', array $warnings = []): array
    {
        return [
            'outcome' => 'ConfirmationRequired',
            'confirmation' => ['key' => $key, 'expiresAt' => ApiFixtures::CREATED_AT + 600_000],
            'warnings' => $warnings === []
                ? [['itemCode' => 'seats', 'requested' => 10, 'remaining' => 4, 'excess' => 6]]
                : $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attached(string $subscriptionAddonId = 'sa-9'): array
    {
        return ['outcome' => 'Attached', 'subscriptionAddonId' => $subscriptionAddonId];
    }

    /**
     * What a private label holds: GET …/private-labels/{tenantId}/package.
     *
     * @param list<array{addonId: string, code: string, quantity: int, subscriptionAddonId: string}> $addons
     *
     * @return array<string, mixed>
     */
    public static function assignment(?string $packageId = 'pkg-1', array $addons = []): array
    {
        if ($packageId === null) {
            return ['assignment' => null];
        }
        $attached = array_map(
            static fn (array $addon): array => $addon + ['name' => $addon['code']],
            $addons,
        );
        $assignment = ['packageId' => $packageId, 'packageCode' => strtoupper($packageId), 'addons' => $attached];

        return ['assignment' => $assignment + ApiFixtures::assignment()];
    }

    /**
     * The reseller's private labels: GET …/private-labels.
     *
     * @param array<array-key, string> $hosts Tenant id => primary host.
     *
     * @return array<string, mixed>
     */
    public static function labels(array $hosts = [], string $status = 'Active'): array
    {
        $rows = [];
        foreach ($hosts as $tenantId => $host) {
            $rows[] = [
                'tenantId' => (string) $tenantId,
                'primaryHost' => $host,
                'customDomain' => null,
                'status' => $status,
            ];
        }

        return ['privateLabels' => $rows, 'provisionedCount' => count($rows), 'period' => '2026-07'];
    }

    /**
     * One private label: GET …/private-labels/{tenantId}.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function label(array $overrides = []): array
    {
        return $overrides + ApiFixtures::privateLabel();
    }

    /**
     * A refusal in the token endpoint's own shape, which the console's envelope did not replace.
     *
     * @return array<string, mixed>
     */
    public static function tokenError(string $code): array
    {
        return ['error' => $code];
    }
}
