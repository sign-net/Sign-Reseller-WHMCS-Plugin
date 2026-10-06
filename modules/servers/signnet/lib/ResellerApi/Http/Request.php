<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Http;

final class Request
{
    private const MASK = '****';

    /**
     * @param array<string, string> $headers
     * @param bool $isReadOnly A POST that only reads, which is as safe to send again as a GET.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly int $timeoutSeconds,
        public readonly int $connectTimeoutSeconds = 10,
        public readonly bool $isReadOnly = false,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = [];
        foreach ($this->headers as $headerName => $headerValue) {
            if (strcasecmp($headerName, $name) !== 0) {
                $headers[$headerName] = $headerValue;
            }
        }
        $headers[$name] = $value;

        return new self(
            $this->method,
            $this->url,
            $headers,
            $this->body,
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->isReadOnly,
        );
    }

    /**
     * The path and query of the URL, which is how messages name a call.
     */
    public function target(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);
        $query = parse_url($this->url, PHP_URL_QUERY);
        $target = is_string($path) && $path !== '' ? $path : '/';

        return is_string($query) && $query !== '' ? $target . '?' . $query : $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp($headerName, 'Authorization') === 0) {
                $headers[$headerName] = self::MASK;
            }
        }
        $body = $this->body === null
            ? null
            : preg_replace('/("(?:api_key|confirmationKey)"\s*:\s*)"[^"]*"/', '$1"' . self::MASK . '"', $this->body);

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'body' => $body,
            'timeoutSeconds' => $this->timeoutSeconds,
            'connectTimeoutSeconds' => $this->connectTimeoutSeconds,
        ];
    }
}
