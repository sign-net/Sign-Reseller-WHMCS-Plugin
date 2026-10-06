<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\Payload;

/**
 * Whether the hosting platform serves the portal hostname.
 *
 * $attached says the host is being served, $verified that its DNS is also correct. Check
 * $attached before telling a customer their portal is live.
 */
final class DomainSetup
{
    /**
     * @param list<DnsRecord> $records The records the customer must create; always present.
     */
    public function __construct(
        public readonly bool $attached,
        public readonly bool $verified,
        public readonly array $records,
        public readonly ?string $note = null,
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
            $payload->bool('attached'),
            $payload->bool('verified'),
            $payload->decodeList('records', DnsRecord::fromArray(...)),
            $payload->stringOrNull('note'),
        );
    }
}
