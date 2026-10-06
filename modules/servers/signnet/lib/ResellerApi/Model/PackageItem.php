<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * One item of a package: how much it includes and how overage is sold, in minor currency units.
 */
final class PackageItem
{
    /**
     * @param string $itemCode An ItemCode value.
     */
    public function __construct(
        public readonly string $itemCode,
        public readonly int $includedQty,
        public readonly int $overageBundleSize = 0,
        public readonly int $overageBundlePriceMinor = 0,
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

        return new self(
            $payload->string('itemCode'),
            $payload->int('includedQty'),
            $payload->int('overageBundleSize'),
            $payload->int('overageBundlePriceMinor'),
        );
    }

    /**
     * @return array{itemCode: string, includedQty: int, overageBundleSize: int, overageBundlePriceMinor: int}
     */
    public function toArray(): array
    {
        return [
            'itemCode' => $this->itemCode,
            'includedQty' => $this->includedQty,
            'overageBundleSize' => $this->overageBundleSize,
            'overageBundlePriceMinor' => $this->overageBundlePriceMinor,
        ];
    }
}
