<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Support;

use SignNet\ResellerApi\Auth\ApiKey;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\Request;
use SignNet\ResellerApi\Http\Response;
use SignNet\ResellerApi\Http\Transport;
use SignNet\Whmcs\Version;

/**
 * Writes every call to Sign.net to WHMCS's module log (Utilities > Logs > Module Log), with the
 * API key, access tokens and confirmation keys masked.
 */
final class LoggingTransport implements Transport
{
    private const MASK = '****';
    private const SECRET_FIELDS = '/("(?:api_key|access_token|confirmationKey|key)"\s*:\s*)"[^"]*"/';

    public function __construct(
        private readonly Transport $inner,
        private readonly ApiKey $apiKey,
    ) {
    }

    public function send(Request $request): Response
    {
        try {
            $response = $this->inner->send($request);
        } catch (TransportException $exception) {
            $this->log($request, 'No response: ' . $exception->getMessage());
            throw $exception;
        }
        $this->log($request, sprintf("HTTP %d\n%s", $response->status, self::mask($response->body)));

        return $response;
    }

    /**
     * Blanks the values of the JSON fields that hold secrets.
     */
    public static function mask(string $json): string
    {
        return preg_replace(self::SECRET_FIELDS, '$1"' . self::MASK . '"', $json) ?? self::MASK;
    }

    private function log(Request $request, string $response): void
    {
        $secrets = [$this->apiKey->reveal()];
        $authorization = $request->header('Authorization');
        if ($authorization !== null && str_starts_with($authorization, 'Bearer ')) {
            $secrets[] = substr($authorization, strlen('Bearer '));
        }
        $requestId = $request->header('X-Request-Id');

        logModuleCall(
            Version::MODULE,
            $request->method . ' ' . $request->target() . ($requestId === null ? '' : ' [' . $requestId . ']'),
            self::mask($request->body ?? ''),
            $response,
            '',
            $secrets,
        );
    }
}
