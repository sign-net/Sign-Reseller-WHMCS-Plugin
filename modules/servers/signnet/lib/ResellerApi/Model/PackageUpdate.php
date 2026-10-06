<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Assert;

/**
 * A change to a package. Only the fields set through the with*() methods are sent; every other
 * field is left unchanged. Each with*() returns a new instance.
 */
final class PackageUpdate
{
    /**
     * @var array<string, mixed>
     */
    private array $changes = [];

    public function withName(string $name): self
    {
        Assert::notBlank('package name', $name);

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

    public function withBasePriceMinor(int $basePriceMinor): self
    {
        return $this->with('basePriceMinor', $basePriceMinor);
    }

    /**
     * Replaces the package's items. New quantities reach new assignments only.
     *
     * @param list<PackageItem> $items
     */
    public function withItems(array $items): self
    {
        Assert::listOf('package items', $items, PackageItem::class);

        return $this->with('items', array_map(static fn (PackageItem $item): array => $item->toArray(), $items));
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
