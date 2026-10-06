<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * One item an add-on grants, per unit of quantity attached.
 */
final class AddonItem
{
    /**
     * @param string $itemCode An ItemCode value.
     */
    public function __construct(
        public readonly string $itemCode,
        public readonly int $grantedQty,
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

        return new self($payload->string('itemCode'), $payload->int('grantedQty'));
    }

    /**
     * @return array{itemCode: string, grantedQty: int}
     */
    public function toArray(): array
    {
        return ['itemCode' => $this->itemCode, 'grantedQty' => $this->grantedQty];
    }
}
