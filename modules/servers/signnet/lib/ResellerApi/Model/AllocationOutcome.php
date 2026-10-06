<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Model;

use SignNet\ResellerApi\Internal\InvalidPayloadException;
use SignNet\ResellerApi\Internal\Payload;

/**
 * What became of a package assignment or add-on attachment.
 *
 * - ASSIGNED: done; $assignmentId and $allocated are set.
 * - ATTACHED: done; $subscriptionAddonId is set.
 * - CONFIRMATION_REQUIRED: nothing was written because it would exceed the allowance; re-send
 *   the identical request with $confirmationKey before $confirmationExpiresAt. $warnings says by
 *   how much.
 * - FAILED: only inside a provisioning result: the tenant exists without a package; $error is
 *   the backend's code, e.g. "NO_SUBSCRIPTION".
 */
final class AllocationOutcome
{
    public const ASSIGNED = 'Assigned';
    public const ATTACHED = 'Attached';
    public const CONFIRMATION_REQUIRED = 'ConfirmationRequired';
    public const FAILED = 'Failed';

    /**
     * @param array<string, int>|null $allocated Quantities by item code.
     * @param int|null $confirmationExpiresAt Milliseconds since the Unix epoch.
     * @param list<Warning> $warnings
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $assignmentId = null,
        public readonly ?string $subscriptionAddonId = null,
        public readonly ?array $allocated = null,
        public readonly ?string $confirmationKey = null,
        public readonly ?int $confirmationExpiresAt = null,
        public readonly array $warnings = [],
        public readonly ?string $error = null,
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
        $outcome = $payload->string('outcome');

        return match ($outcome) {
            self::ASSIGNED => new self(
                $outcome,
                assignmentId: $payload->string('assignmentId'),
                allocated: $payload->intMap('allocated'),
            ),
            self::ATTACHED => new self($outcome, subscriptionAddonId: $payload->string('subscriptionAddonId')),
            self::CONFIRMATION_REQUIRED => self::confirmationRequired($payload),
            self::FAILED => new self($outcome, error: $payload->string('error')),
            default => throw InvalidPayloadException::forField(
                'outcome',
                'one of Assigned, Attached, ConfirmationRequired or Failed',
            ),
        };
    }

    public function isDone(): bool
    {
        return $this->outcome === self::ASSIGNED || $this->outcome === self::ATTACHED;
    }

    public function isConfirmationRequired(): bool
    {
        return $this->outcome === self::CONFIRMATION_REQUIRED;
    }

    private static function confirmationRequired(Payload $payload): self
    {
        $confirmation = $payload->object('confirmation');

        return new self(
            self::CONFIRMATION_REQUIRED,
            confirmationKey: $confirmation->string('key'),
            confirmationExpiresAt: $confirmation->int('expiresAt'),
            warnings: $payload->decodeList('warnings', Warning::fromArray(...)),
        );
    }
}
