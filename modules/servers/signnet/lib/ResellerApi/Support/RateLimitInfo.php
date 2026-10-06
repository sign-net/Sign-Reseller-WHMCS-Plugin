<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Support;

/**
 * The rate-limit state a response reported through its RateLimit-* and Retry-After headers.
 *
 * When several limiters guard a route, the headers describe only the innermost one, so treat
 * the figures as a pacing hint rather than a guarantee.
 */
final class RateLimitInfo
{
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?int $resetSeconds,
        public readonly ?int $retryAfterSeconds,
    ) {
    }

    /**
     * @param array<string, string> $headers Response headers keyed by lower-cased name.
     * @param int $now Unix time in seconds, to turn an HTTP-date Retry-After into a delay.
     */
    public static function fromHeaders(array $headers, int $now): ?self
    {
        $info = new self(
            self::parseSeconds($headers['ratelimit-limit'] ?? null),
            self::parseSeconds($headers['ratelimit-remaining'] ?? null),
            self::parseSeconds($headers['ratelimit-reset'] ?? null),
            self::parseRetryAfter($headers['retry-after'] ?? null, $now),
        );

        return $info->isEmpty() ? null : $info;
    }

    /**
     * How long to wait before calling again: Retry-After when given, else the window reset.
     */
    public function waitSeconds(): ?int
    {
        return $this->retryAfterSeconds ?? $this->resetSeconds;
    }

    private function isEmpty(): bool
    {
        return $this->limit === null
            && $this->remaining === null
            && $this->resetSeconds === null
            && $this->retryAfterSeconds === null;
    }

    private static function parseSeconds(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return preg_match('/^\d{1,10}$/D', $value) === 1 ? (int) $value : null;
    }

    private static function parseRetryAfter(?string $value, int $now): ?int
    {
        if ($value === null) {
            return null;
        }
        $seconds = self::parseSeconds($value);
        if ($seconds !== null) {
            return $seconds;
        }
        $retryAt = strtotime(trim($value));

        return $retryAt === false ? null : max(0, $retryAt - $now);
    }
}
