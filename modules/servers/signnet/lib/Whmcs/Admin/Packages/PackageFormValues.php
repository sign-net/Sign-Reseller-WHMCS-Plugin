<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Packages;

use SignNet\ResellerApi\Model\BillingCycle;
use SignNet\ResellerApi\Model\ItemCode;
use SignNet\ResellerApi\Model\Package;
use SignNet\ResellerApi\Model\PackageInput;
use SignNet\ResellerApi\Model\PackageItem;
use SignNet\ResellerApi\Model\PackageUpdate;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Admin\FormNumbers;
use SignNet\Whmcs\Catalogue\PriceMapper;

/**
 * The package form's fields as typed, and the request to Sign.net they amount to.
 *
 * @phpstan-type ItemFields array{included: string, bundleSize: string, bundlePrice: string}
 */
final class PackageFormValues
{
    /**
     * @param string $basePrice An amount such as "19.00".
     * @param array<array-key, ItemFields> $items Item code => its fields; a blank "included" leaves the item out.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $description,
        public readonly string $currency,
        public readonly string $billingCycle,
        public readonly string $basePrice,
        public readonly bool $isActive,
        public readonly array $items,
    ) {
    }

    public static function blank(string $currency): self
    {
        return new self('', '', '', $currency, BillingCycle::MONTHLY, '', true, []);
    }

    public static function fromPackage(Package $package): self
    {
        $items = [];
        foreach ($package->items as $item) {
            $items[$item->itemCode] = [
                'included' => (string) $item->includedQty,
                'bundleSize' => $item->overageBundleSize === 0 ? '' : (string) $item->overageBundleSize,
                'bundlePrice' => $item->overageBundlePriceMinor === 0
                    ? ''
                    : PriceMapper::toDecimal($item->overageBundlePriceMinor),
            ];
        }

        return new self(
            $package->code,
            $package->name,
            (string) $package->description,
            $package->currency,
            $package->billingCycle,
            PriceMapper::toDecimal($package->basePriceMinor),
            $package->isActive,
            $items,
        );
    }

    public static function fromRequest(AdminRequest $request): self
    {
        $items = [];
        foreach ($request->postGroup('items') as $itemCode => $fields) {
            $items[$itemCode] = [
                'included' => $fields['included'] ?? '',
                'bundleSize' => $fields['bundle_size'] ?? '',
                'bundlePrice' => $fields['bundle_price'] ?? '',
            ];
        }

        return new self(
            $request->post('code'),
            $request->post('name'),
            $request->post('description'),
            $request->post('currency'),
            $request->post('billing_cycle'),
            $request->post('base_price'),
            $request->posted('is_active'),
            $items,
        );
    }

    /**
     * @throws \InvalidArgumentException Naming the field, when one cannot be read.
     */
    public function toInput(): PackageInput
    {
        return new PackageInput(
            $this->code,
            $this->name,
            $this->description === '' ? null : $this->description,
            $this->currency,
            $this->billingCycle,
            FormNumbers::price('Base price', $this->basePrice),
            $this->packageItems(),
        );
    }

    /**
     * Only what differs from the package as Sign.net holds it; code, currency and cycle never change.
     * Sending items replaces them all, so the items this form has no field for go back unchanged.
     *
     * @throws \InvalidArgumentException Naming the field, when one cannot be read.
     */
    public function toUpdate(Package $current): PackageUpdate
    {
        $update = new PackageUpdate();
        if ($this->name !== $current->name) {
            $update = $update->withName($this->name);
        }
        if ($this->description !== trim((string) $current->description)) {
            $update = $update->withDescription($this->description === '' ? null : $this->description);
        }
        $basePrice = FormNumbers::price('Base price', $this->basePrice);
        if ($basePrice !== $current->basePriceMinor) {
            $update = $update->withBasePriceMinor($basePrice);
        }
        $items = [...$this->packageItems(), ...self::unshown($current->items)];
        if (self::comparable($items) !== self::comparable($current->items)) {
            $update = $update->withItems($items);
        }

        return $this->isActive === $current->isActive ? $update : $update->withActive($this->isActive);
    }

    /**
     * @return list<PackageItem>
     */
    private function packageItems(): array
    {
        $items = [];
        foreach (ItemCode::ALL as $itemCode) {
            $fields = $this->items[$itemCode] ?? null;
            if ($fields === null || $fields['included'] === '') {
                continue;
            }
            $label = CatalogueLabels::item($itemCode);
            $bundleSize = $fields['bundleSize'] === '' ? '0' : $fields['bundleSize'];
            $bundlePrice = $fields['bundlePrice'] === '' ? '0' : $fields['bundlePrice'];
            $items[] = new PackageItem(
                $itemCode,
                FormNumbers::wholeNumber('Included ' . strtolower($label), $fields['included']),
                FormNumbers::wholeNumber($label . ' overage bundle size', $bundleSize),
                FormNumbers::price($label . ' overage bundle price', $bundlePrice),
            );
        }

        return $items;
    }

    /**
     * @param list<PackageItem> $items
     *
     * @return list<PackageItem> Those whose item code the form has no field for.
     */
    private static function unshown(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (PackageItem $item): bool => !in_array($item->itemCode, ItemCode::ALL, true),
        ));
    }

    /**
     * @param list<PackageItem> $items
     *
     * @return array<string, array<string, int|string>>
     */
    private static function comparable(array $items): array
    {
        $byCode = [];
        foreach ($items as $item) {
            $byCode[$item->itemCode] = $item->toArray();
        }
        ksort($byCode);

        return $byCode;
    }
}
