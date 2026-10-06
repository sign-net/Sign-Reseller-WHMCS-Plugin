<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Assert;

/**
 * A change to an add-on. Only the fields set through the with*() methods are sent; every other
 * field is left unchanged. Each with*() returns a new instance.
 */
final class AddonUpdate
{
    /**
     * @var array<string, mixed>
     */
    private array $changes = [];

    public function withName(string $name): self
    {
        Assert::notBlank('add-on name', $name);

        return $this->with('name', $name);
    }

    /**
     * @param string|null $description Null removes the description.
     */
    public function withDescription(?string $description): self
    {
        return $this->with('description', $description);
    }

    public function withActive(bool $isActive): self
    {
        return $this->with('isActive', $isActive);
    }

    public function withPriceMinor(int $priceMinor): self
    {
        return $this->with('priceMinor', $priceMinor);
    }

    /**
     * Replaces the add-on's items; refused with addon_in_use once it was ever attached.
     *
     * @param list<AddonItem> $items
     */
    public function withItems(array $items): self
    {
        Assert::listOf('add-on items', $items, AddonItem::class);

        return $this->with('items', array_map(static fn (AddonItem $item): array => $item->toArray(), $items));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->changes;
    }

    private function with(string $field, mixed $value): self
    {
        $update = clone $this;
        $update->changes[$field] = $value;

        return $update;
    }
}
