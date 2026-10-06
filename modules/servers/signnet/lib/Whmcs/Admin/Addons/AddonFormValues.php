<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Addons;

use SignNet\ResellerApi\Model\Addon;
use SignNet\ResellerApi\Model\AddonInput;
use SignNet\ResellerApi\Model\AddonItem;
use SignNet\ResellerApi\Model\AddonUpdate;
use SignNet\ResellerApi\Model\BillingCycle;
use SignNet\ResellerApi\Model\ItemCode;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Admin\FormNumbers;
use SignNet\Whmcs\Catalogue\PriceMapper;

/**
 * The add-on form's fields as typed, and the request to Sign.net they amount to.
 */
final class AddonFormValues
{
    /**
     * @param string $price The price of one unit, such as "5.00".
     * @param array<array-key, string> $grants Item code => how many one unit grants; blank leaves the item out.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $description,
        public readonly string $currency,
        public readonly string $billingCycle,
        public readonly string $price,
        public readonly bool $isActive,
        public readonly array $grants,
    ) {
    }

    public static function blank(string $currency): self
    {
        return new self('', '', '', $currency, BillingCycle::MONTHLY, '', true, []);
    }

    public static function fromAddon(Addon $addon): self
    {
        $grants = [];
        foreach ($addon->items as $item) {
            $grants[$item->itemCode] = (string) $item->grantedQty;
        }

        return new self(
            $addon->code,
            $addon->name,
            (string) $addon->description,
            $addon->currency,
            $addon->billingCycle,
            PriceMapper::toDecimal($addon->priceMinor),
            $addon->isActive,
            $grants,
        );
    }

    public static function fromRequest(AdminRequest $request): self
    {
        $grants = [];
        foreach ($request->postGroup('items') as $itemCode => $fields) {
            $grants[$itemCode] = $fields['granted'] ?? '';
        }

        return new self(
            $request->post('code'),
            $request->post('name'),
            $request->post('description'),
            $request->post('currency'),
            $request->post('billing_cycle'),
            $request->post('price'),
            $request->posted('is_active'),
            $grants,
        );
    }

    /**
     * @throws \InvalidArgumentException Naming the field, when one cannot be read.
     */
    public function toInput(): AddonInput
    {
        return new AddonInput(
            $this->code,
            $this->name,
            $this->description === '' ? null : $this->description,
            $this->currency,
            FormNumbers::price('Price', $this->price),
            $this->billingCycle,
            $this->addonItems(),
        );
    }

    /**
     * Only what differs from the add-on as Sign.net holds it. Grants are left alone once they are
     * locked, which is also when the form stops sending them. Sending grants replaces them all, so the
     * grants this form has no field for go back unchanged.
     *
     * @throws \InvalidArgumentException Naming the field, when one cannot be read.
     */
    public function toUpdate(Addon $current): AddonUpdate
    {
        $update = new AddonUpdate();
        if ($this->name !== $current->name) {
            $update = $update->withName($this->name);
        }
        if ($this->description !== trim((string) $current->description)) {
            $update = $update->withDescription($this->description === '' ? null : $this->description);
        }
        $price = FormNumbers::price('Price', $this->price);
        if ($price !== $current->priceMinor) {
            $update = $update->withPriceMinor($price);
        }
        $items = $current->grantsLocked ? null : [...$this->addonItems(), ...self::unshown($current->items)];
        if ($items !== null && self::comparable($items) !== self::comparable($current->items)) {
            $update = $update->withItems($items);
        }

        return $this->isActive === $current->isActive ? $update : $update->withActive($this->isActive);
    }

    /**
     * @return list<AddonItem>
     */
    private function addonItems(): array
    {
        $items = [];
        foreach (ItemCode::ALL as $itemCode) {
            $granted = $this->grants[$itemCode] ?? '';
            if ($granted !== '') {
                $label = sprintf('%s granted per unit', CatalogueLabels::item($itemCode));
                $items[] = new AddonItem($itemCode, FormNumbers::wholeNumber($label, $granted));
            }
        }

        return $items;
    }

    /**
     * @param list<AddonItem> $items
     *
     * @return list<AddonItem> Those whose item code the form has no field for.
     */
    private static function unshown(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (AddonItem $item): bool => !in_array($item->itemCode, ItemCode::ALL, true),
        ));
    }

    /**
     * @param list<AddonItem> $items
     *
     * @return array<string, int>
     */
    private static function comparable(array $items): array
    {
        $byCode = [];
        foreach ($items as $item) {
            $byCode[$item->itemCode] = $item->grantedQty;
        }
        ksort($byCode);

        return $byCode;
    }
}
