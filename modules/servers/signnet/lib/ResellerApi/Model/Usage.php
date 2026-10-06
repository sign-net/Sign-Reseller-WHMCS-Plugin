<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * What the reseller's private labels actually did in a calendar month (UTC).
 * Counts only, never floored by allocation, so it can be lower than the bill.
 */
final class Usage
{
    /**
     * @param string $period The month, YYYY-MM.
     * @param array<string, int> $total Counts by metric: documents, points, seats, templates.
     * @param list<UsagePrivateLabel> $privateLabels
     */
    public function __construct(
        public readonly string $period,
        public readonly array $total,
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

        return new self(
            $payload->string('period'),
            $payload->intMap('total'),
            $payload->decodeList('privateLabels', UsagePrivateLabel::fromArray(...)),
        );
    }
}
