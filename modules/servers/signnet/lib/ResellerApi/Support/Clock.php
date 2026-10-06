<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Support;

interface Clock
{
    /**
     * The current Unix time, in seconds.
     */
    public function now(): int;
}
