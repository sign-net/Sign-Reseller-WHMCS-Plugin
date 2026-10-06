<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * An add-on of the reseller's own catalogue, with what one unit grants. Prices are in minor
 * currency units; timestamps are milliseconds since the Unix epoch.
 */
final class Addon
{
    /**
     * @param string $billingCycle A BillingCycle value.
     * @param list<AddonItem> $items
     * @param bool $grantsLocked True once the add-on was ever attached: its items can then no
     *     longer change, though its price can.
     * @param int $activeAttachments Private labels it is attached to now.
     */
    public function __construct(
        public readonly string $addonId,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $currency,
        public readonly int $priceMinor,
        public readonly string $billingCycle,
        public readonly bool $isActive,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly array $items,
        public readonly bool $grantsLocked,
        public readonly int $activeAttachments,
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
        $summary = $payload->decode('addon', AddonSummary::fromArray(...));

        return new self(
            $summary->addonId,
            $summary->code,
            $summary->name,
            $summary->description,
            $summary->currency,
            $summary->priceMinor,
            $summary->billingCycle,
            $summary->isActive,
            $summary->createdAt,
            $summary->updatedAt,
            $payload->decodeList('items', AddonItem::fromArray(...)),
            $payload->bool('areGrantsLocked'),
            $payload->int('activeAttachments'),
        );
    }
}
