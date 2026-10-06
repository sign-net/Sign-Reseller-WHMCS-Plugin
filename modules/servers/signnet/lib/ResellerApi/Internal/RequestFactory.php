<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

use SignNet\ResellerApi\ClientConfig;
use SignNet\ResellerApi\Http\Request;

/**
 * @internal
 */
final class RequestFactory
{
    public function __construct(private readonly ClientConfig $config)
    {
    }

    /**
     * @param array<string, mixed>|null $payload Sent as a JSON object; [] sends "{}".
     * @param bool $isReadOnly A POST that only reads, so may be retried as a GET is.
     */
    public function create(
        string $method,
        string $path,
        ?array $payload,
        int $timeoutSeconds,
        bool $isReadOnly = false,
    ): Request {
        $headers = [
            'User-Agent' => $this->config->userAgent,
            'Accept' => 'application/json',
            'X-Request-Id' => self::newRequestId(),
        ];
        $body = null;
        if ($payload !== null) {
            $body = self::encode($payload);
            $headers['Content-Type'] = 'application/json';
        }

        return new Request(
            $method,
            $this->config->baseUrl . $path,
            $headers,
            $body,
            $timeoutSeconds,
            $this->config->connectTimeout,
            $isReadOnly,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        if ($payload === []) {
            return '{}';
        }
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'The request cannot be encoded as JSON; is all of its text valid UTF-8?',
                0,
                $exception,
            );
        }
    }

    private static function newRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
