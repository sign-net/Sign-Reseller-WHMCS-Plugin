<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * The reseller's allowance, what it has promised out, and what its labels did.
 *
 * A null $plan means Sign.net has not put the reseller on a plan at all, which is not the same
 * as a plan whose items read zero: packages cannot be assigned without one.
 */
final class Quota
{
    /**
     * @param int|null $windowFrom Milliseconds since the Unix epoch; null without a plan.
     * @param int|null $windowTo Milliseconds since the Unix epoch; null without a plan.
     * @param list<QuotaItem> $items
     * @param list<QuotaPrivateLabel> $privateLabels
     */
    public function __construct(
        public readonly ?QuotaPlan $plan,
        public readonly ?int $windowFrom,
        public readonly ?int $windowTo,
        public readonly array $items,
        public readonly array $privateLabels,
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
        $window = $payload->objectOrNull('window');

        return new self(
            $payload->decodeOrNull('subscription', QuotaPlan::fromArray(...)),
            $window?->int('from'),
            $window?->int('to'),
            $payload->decodeList('items', QuotaItem::fromArray(...)),
            $payload->decodeList('byPrivateLabel', QuotaPrivateLabel::fromArray(...)),
        );
    }

    public function hasPlan(): bool
    {
        return $this->plan !== null;
    }

    public function item(string $itemCode): ?QuotaItem
    {
        foreach ($this->items as $item) {
            if ($item->itemCode === $itemCode) {
                return $item;
            }
        }

        return null;
    }
}
