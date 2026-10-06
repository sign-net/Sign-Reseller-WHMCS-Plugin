<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Model\Warning;

/**
 * How far a portal got towards its plan.
 */
final class ConvergeResult
{
    /**
     * @param list<Warning> $shortfalls Why it is held: what the next allocation would take past the allowance.
     * @param list<string> $notes What the changes made cost, for the activity log.
     */
    private function __construct(
        public readonly bool $isDone,
        public readonly array $shortfalls,
        public readonly array $notes,
    ) {
    }

    /**
     * @param list<string> $notes
     */
    public static function done(array $notes = []): self
    {
        return new self(true, [], $notes);
    }

    /**
     * @param list<Warning> $shortfalls
     * @param list<string> $notes
     */
    public static function held(array $shortfalls, array $notes = []): self
    {
        return new self(false, $shortfalls, $notes);
    }
}
