<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Assert;

/**
 * A new package for the reseller's catalogue. Codes are stored upper case, are unique per
 * reseller and can never be reused, even after the package is deactivated.
 */
final class PackageInput
{
    /**
     * @param string|null $description Always sent; null leaves the package without one.
     * @param string $billingCycle A BillingCycle value.
     * @param int $basePriceMinor In minor currency units; informational, never billed by Sign.net.
     * @param list<PackageItem> $items
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $currency,
        public readonly string $billingCycle,
        public readonly int $basePriceMinor,
        public readonly array $items,
    ) {
        Assert::notBlank('package code', $code);
        Assert::notBlank('package name', $name);
        Assert::notBlank('currency', $currency);
        Assert::notBlank('billing cycle', $billingCycle);
        Assert::listOf('package items', $items, PackageItem::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'currency' => strtoupper($this->currency),
            'billingCycle' => $this->billingCycle,
            'basePriceMinor' => $this->basePriceMinor,
            'items' => array_map(static fn (PackageItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
