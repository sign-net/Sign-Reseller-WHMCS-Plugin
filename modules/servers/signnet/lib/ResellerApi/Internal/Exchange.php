<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;

/**
 * A successful response together with the request that produced it.
 *
 * The console answers every success as {"status": "OK", "data": ...}; data() and requireOk()
 * read that envelope, while decode() reads a bare body such as the token endpoint's.
 *
 * @internal
 */
final class Exchange
{
    public function __construct(public readonly Request $request, public readonly Response $response)
    {
    }

    /**
     * @template T
     *
     * @param callable(array<mixed>): T $decoder
     *
     * @return T
     *
     * @throws ServerException When the body is not JSON or lacks what the decoder needs.
     */
    public function decode(callable $decoder): mixed
    {
        try {
            return $decoder($this->response->json());
        } catch (\UnexpectedValueException $exception) {
            throw $this->unexpected($exception);
        }
    }

    /**
     * @template T
     *
     * @param callable(array<mixed>): T $decoder Given the envelope's data.
     *
     * @return T
     *
     * @throws ServerException When the body is not an OK envelope, or its data lacks what the
     *     decoder needs.
     */
    public function data(callable $decoder): mixed
    {
        try {
            return Payload::of($this->okEnvelope())->decode('data', $decoder);
        } catch (\UnexpectedValueException $exception) {
            throw $this->unexpected($exception);
        }
    }

    /**
     * For a call whose answer carries nothing the caller needs.
     *
     * @throws ServerException When the body is not an OK envelope.
     */
    public function requireOk(): void
    {
        try {
            $this->okEnvelope();
        } catch (\UnexpectedValueException $exception) {
            throw $this->unexpected($exception);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function okEnvelope(): array
    {
        $body = $this->response->json();
        if (($body['status'] ?? null) !== 'OK') {
            throw InvalidPayloadException::forField('status', '"OK"');
        }

        return $body;
    }

    private function unexpected(\UnexpectedValueException $exception): ServerException
    {
        return new ServerException(
            sprintf(
                'Sign.net API call %s %s answered HTTP %d with an unexpected body: %s.',
                $this->request->method,
                $this->request->target(),
                $this->response->status,
                $exception->getMessage(),
            ),
            $this->response->status,
            null,
            $this->request->header('X-Request-Id'),
            null,
            $exception,
        );
    }
}
