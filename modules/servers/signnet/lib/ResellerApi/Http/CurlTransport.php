<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Http;

use SignNet\ResellerApi\Exception\TransportException;

/**
 * Sends requests with ext-curl. TLS peer and host verification are always on and redirects are
 * never followed.
 */
final class CurlTransport implements Transport
{
    /**
     * Failures that happen before any byte of the request can have reached the server.
     */
    private const NOT_SENT_ERRORS = [
        CURLE_UNSUPPORTED_PROTOCOL,
        CURLE_URL_MALFORMAT,
        CURLE_COULDNT_RESOLVE_PROXY,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
        CURLE_SSL_ENGINE_NOTFOUND,
        CURLE_SSL_ENGINE_SETFAILED,
        CURLE_SSL_CERTPROBLEM,
        CURLE_SSL_CIPHER,
        CURLE_SSL_CACERT,
        CURLE_SSL_CACERT_BADFILE,
    ];

    public function send(Request $request): Response
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Could not initialise cURL.', false, $request->header('X-Request-Id'));
        }

        $headers = [];
        $collectHeader = static function (\CurlHandle $curl, string $line) use (&$headers): int {
            self::collectHeader($headers, $line);

            return strlen($line);
        };
        if (!curl_setopt_array($handle, $this->options($request, $collectHeader))) {
            throw new TransportException('Could not configure cURL.', false, $request->header('X-Request-Id'));
        }

        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw $this->failure($handle, $request);
        }

        return new Response(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
    }

    /**
     * @return array<int, mixed>
     */
    private function options(Request $request, \Closure $collectHeader): array
    {
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headerLines($request),
            CURLOPT_HEADERFUNCTION => $collectHeader,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function headerLines(Request $request): array
    {
        // An empty Expect header stops cURL from waiting for "100 Continue" on larger bodies.
        $lines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                throw new \InvalidArgumentException(sprintf('The %s header contains a line break.', $name));
            }
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * @param array<string, string> $headers
     * @param-out array<string, string> $headers
     */
    private static function collectHeader(array &$headers, string $line): void
    {
        $line = trim($line);
        if (str_starts_with($line, 'HTTP/')) {
            $headers = [];

            return;
        }
        $separator = strpos($line, ':');
        if ($separator === false) {
            return;
        }
        $name = strtolower(trim(substr($line, 0, $separator)));
        $value = trim(substr($line, $separator + 1));
        $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
    }

    private function failure(\CurlHandle $handle, Request $request): TransportException
    {
        $errorNumber = curl_errno($handle);

        return new TransportException(
            sprintf(
                '%s %s got no response: cURL error %d: %s',
                $request->method,
                $request->url,
                $errorNumber,
                curl_error($handle),
            ),
            $this->requestSent($handle, $errorNumber, $request),
            $request->header('X-Request-Id'),
        );
    }

    private function requestSent(\CurlHandle $handle, int $errorNumber, Request $request): ?bool
    {
        if (in_array($errorNumber, self::NOT_SENT_ERRORS, true)) {
            return false;
        }
        if ($errorNumber === CURLE_OPERATION_TIMEDOUT && !$this->isConnected($handle, $request)) {
            return false;
        }

        return curl_getinfo($handle, CURLINFO_REQUEST_SIZE) > 0 ? true : null;
    }

    private function isConnected(\CurlHandle $handle, Request $request): bool
    {
        if (strncasecmp($request->url, 'https:', 6) === 0) {
            return curl_getinfo($handle, CURLINFO_APPCONNECT_TIME) > 0;
        }

        return curl_getinfo($handle, CURLINFO_CONNECT_TIME) > 0;
    }
}
