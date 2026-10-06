<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Fake;

use SignNet\ResellerApi\Support\Sleeper;

final class FakeSleeper implements Sleeper
{
    /**
     * @var list<int>
     */
    private array $sleeps = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }

    /**
     * @return list<int>
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }
}
