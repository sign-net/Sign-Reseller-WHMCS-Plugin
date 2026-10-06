<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Support;

interface Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
