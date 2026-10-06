<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Http;

use SignNet\ResellerApi\Exception\TransportException;

interface Transport
{
    /**
     * Sends the request once and returns the response, whatever its status.
     *
     * @throws TransportException When no response was received.
     */
    public function send(Request $request): Response;
}
