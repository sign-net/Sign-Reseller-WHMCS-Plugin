<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Fake;

use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Http\Transport;

/**
 * Answers requests from a queue of responses and failures, and records every request.
 */
final class FakeTransport implements Transport
{
    /**
     * @var list<Response|TransportException>
     */
    private array $queue = [];

    /**
     * @var list<Request>
     */
    private array $requests = [];

    public function queue(Response|TransportException ...$outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $this->queue[] = $outcome;
        }
    }

    /**
     * A console endpoint's success, carrying $data in its envelope.
     *
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public function queueData(array $data, array $headers = []): void
    {
        $this->queueJson(200, ['status' => 'OK', 'data' => $data], $headers);
    }

    /**
     * A console endpoint's refusal with error code $code, in its envelope.
     *
     * @param array<string, string> $headers
     */
    public function queueError(int $status, string $code, array $headers = []): void
    {
        $this->queueJson($status, [
            'status' => $status >= 500 ? 'Fail' : 'Err',
            'error' => ['code' => strtoupper($code), 'message' => 'Refused by the fake Sign.net.'],
        ], $headers);
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    public function queueJson(int $status, array $body, array $headers = []): void
    {
        $this->queue(new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $outcome = array_shift($this->queue);
        if ($outcome === null) {
            throw new \LogicException(sprintf('No response queued for %s %s.', $request->method, $request->url));
        }
        if ($outcome instanceof TransportException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): Request
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }

    public function pendingCount(): int
    {
        return count($this->queue);
    }
}
