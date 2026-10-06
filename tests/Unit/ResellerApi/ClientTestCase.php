<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi;

use SignNet\ResellerApi\ResellerClient;

abstract class ClientTestCase extends ApiTestCase
{
    protected ResellerClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new ResellerClient($this->config, $this->transport, $this->store, $this->clock, $this->sleeper);
    }
}
