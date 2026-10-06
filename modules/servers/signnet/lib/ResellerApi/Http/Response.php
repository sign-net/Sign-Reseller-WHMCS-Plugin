<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Http;

final class Response
{
    /**
     * @var array<string, string> Header values keyed by lower-cased name.
     */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<mixed>
     *
     * @throws \UnexpectedValueException When the body is not a JSON object or array.
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('the body is not valid JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('the body is not a JSON object');
        }

        return $decoded;
    }
}
