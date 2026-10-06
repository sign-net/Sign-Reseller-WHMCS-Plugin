<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Fake;

use SignNet\ResellerApi\Support\Clock;

final class FakeClock implements Clock
{
    public function __construct(private int $now = 1_760_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
