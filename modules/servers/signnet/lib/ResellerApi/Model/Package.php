<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * A package of the reseller's own catalogue, with its items. Prices are in minor currency units;
 * timestamps are milliseconds since the Unix epoch.
 */
final class Package
{
    /**
     * @param string $billingCycle A BillingCycle value.
     * @param list<PackageItem> $items
     * @param int $assignedCount Live private labels holding the package.
     */
    public function __construct(
        public readonly string $packageId,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $currency,
        public readonly string $billingCycle,
        public readonly int $basePriceMinor,
        public readonly bool $isActive,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly array $items,
        public readonly int $assignedCount,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException When a required field is missing or mistyped.
     */
    public static function fromArray(array $data): self
    {
        $payload = Payload::of($data);
        $summary = $payload->decode('billingPackage', PackageSummary::fromArray(...));

        return new self(
            $summary->packageId,
            $summary->code,
            $summary->name,
            $summary->description,
            $summary->currency,
            $summary->billingCycle,
            $summary->basePriceMinor,
            $summary->isActive,
            $summary->createdAt,
            $summary->updatedAt,
            $payload->decodeList('items', PackageItem::fromArray(...)),
            $payload->int('assignedCount'),
        );
    }
}
