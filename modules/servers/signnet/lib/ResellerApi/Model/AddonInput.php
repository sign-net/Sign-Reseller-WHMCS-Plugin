<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Assert;

/**
 * A new add-on for the reseller's catalogue. Codes are stored upper case, are unique per reseller
 * and can never be reused.
 */
final class AddonInput
{
    /**
     * @param string|null $description Always sent; null leaves the add-on without one.
     * @param int $priceMinor In minor currency units; informational, never billed by Sign.net.
     * @param string $billingCycle A BillingCycle value.
     * @param list<AddonItem> $items
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $currency,
        public readonly int $priceMinor,
        public readonly string $billingCycle,
        public readonly array $items,
    ) {
        Assert::notBlank('add-on code', $code);
        Assert::notBlank('add-on name', $name);
        Assert::notBlank('currency', $currency);
        Assert::notBlank('billing cycle', $billingCycle);
        Assert::listOf('add-on items', $items, AddonItem::class);
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
            'priceMinor' => $this->priceMinor,
            'billingCycle' => $this->billingCycle,
            'items' => array_map(static fn (AddonItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
