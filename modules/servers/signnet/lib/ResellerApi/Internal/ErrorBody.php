<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Internal;

/**
 * What an error response body says, in either shape the backend uses: the token endpoint's and the
 * rate limiters' {"error": "code or sentence"}, or the console's {"status": "Err"|"Fail", "error":
 * {"code": "CODE", ...}}.
 *
 * @internal
 */
final class ErrorBody
{
    private const ROUTE_NOT_FOUND_CODE = 'not_found';

    /**
     * @param bool $isRecognized Whether the body has one of the two known shapes.
     * @param string|null $code Lower-cased; null when absent or a sentence rather than a code.
     */
    private function __construct(
        public readonly bool $isRecognized,
        public readonly bool $isEnvelope,
        public readonly ?string $code,
    ) {
    }

    public static function parse(string $body): self
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(false, false, null);
        }
        if (!is_array($data)) {
            return new self(false, false, null);
        }

        $error = $data['error'] ?? null;
        if (is_string($error)) {
            return new self(true, false, self::normalizeCode($error));
        }
        if (is_string($data['status'] ?? null) && is_array($error) && is_string($error['code'] ?? null)) {
            return new self(true, true, self::normalizeCode($error['code']));
        }

        return new self(false, false, null);
    }

    /**
     * Whether this is the backend's answer for a route it does not have.
     */
    public function isRouteNotFound(): bool
    {
        return $this->isEnvelope && $this->code === self::ROUTE_NOT_FOUND_CODE;
    }

    private static function normalizeCode(string $value): ?string
    {
        $code = strtolower(trim($value));

        return preg_match('/^[a-z][a-z0-9_]*$/D', $code) === 1 ? $code : null;
    }
}
