<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use SignNet\ResellerApi\Model\Package;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Provisioning\ProductSettings;
use SignNet\Whmcs\Version;
use WHMCS\Database\Capsule;

/**
 * WHMCS products that sell a Sign.net package through this module, with the custom fields in
 * which customers order their portal.
 */
final class ProductFactory
{
    /**
     * Creates a product priced from the package and answers its id. Only the package's currency is
     * priced; WHMCS's other currencies are left for the administrator.
     *
     * @throws CatalogueException When WHMCS lacks the package's currency or refuses the product.
     */
    public function create(Package $package, ProductSpec $spec): int
    {
        $currencyId = Currencies::idForCode($package->currency);
        $result = localAPI('AddProduct', self::addProductValues($package, $spec, $currencyId));
        if (($result['result'] ?? null) !== 'success') {
            throw new CatalogueException(
                'WHMCS did not create the product: ' . (string) ($result['message'] ?? 'it gave no reason') . '.',
            );
        }
        $productId = (int) ($result['pid'] ?? 0);
        if ($productId <= 0) {
            throw new CatalogueException('WHMCS created the product but did not say which one it is.');
        }
        PortalFields::ensureFor($productId);

        return $productId;
    }

    /**
     * Makes an existing product sell the package through this module. Its over-allowance and
     * termination settings are kept when they are this module's, and default otherwise.
     *
     * @throws CatalogueException When there is no such product.
     */
    public function link(int $productId, string $packageId): void
    {
        $product = Rows::first(
            Capsule::table('tblproducts')->select('configoption2', 'configoption3')->where('id', $productId),
        );
        if ($product === null) {
            throw new CatalogueException(sprintf('WHMCS has no product #%d.', $productId));
        }
        $settings = ProductSettings::fromParams(['configoption1' => $packageId] + $product);
        Capsule::table('tblproducts')
            ->where('id', $productId)
            ->update(['servertype' => Version::MODULE] + $settings->toConfigOptions());
        PortalFields::ensureFor($productId);
    }

    /**
     * @return array<string, mixed> AddProduct's parameters.
     */
    private static function addProductValues(Package $package, ProductSpec $spec, int $currencyId): array
    {
        $values = [
            'type' => 'other',
            'gid' => $spec->groupId,
            'name' => $spec->name,
            'paytype' => PriceMapper::payType($package->billingCycle),
            'pricing' => [$currencyId => PriceMapper::productPricing($package->billingCycle, $package->basePriceMinor)],
            'module' => Version::MODULE,
            'autosetup' => 'payment',
            'welcomeemail' => $spec->welcomeEmailId,
        ] + (new ProductSettings($package->packageId, $spec->overAllowance, $spec->termination))->toConfigOptions();
        if ($spec->serverGroupId !== null) {
            $values['servergroupid'] = $spec->serverGroupId;
        }

        return $values;
    }
}
