<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Support\RateLimitInfo;

final class RateLimitInfoTest extends TestCase
{
    private const NOW = 1_760_000_000;

    #[Test]
    public function itReadsTheDraftRateLimitHeaders(): void
    {
        $info = RateLimitInfo::fromHeaders(
            ['ratelimit-limit' => '60', 'ratelimit-remaining' => ' 12 ', 'ratelimit-reset' => '30'],
            self::NOW,
        );

        self::assertNotNull($info);
        self::assertSame(60, $info->limit);
        self::assertSame(12, $info->remaining);
        self::assertSame(30, $info->resetSeconds);
        self::assertNull($info->retryAfterSeconds);
        self::assertSame(30, $info->waitSeconds());
    }

    #[Test]
    public function itReadsRetryAfterGivenInSeconds(): void
    {
        $info = RateLimitInfo::fromHeaders(['retry-after' => '120', 'ratelimit-reset' => '30'], self::NOW);

        self::assertNotNull($info);
        self::assertSame(120, $info->retryAfterSeconds);
        self::assertSame(120, $info->waitSeconds());
    }

    #[Test]
    public function itReadsRetryAfterGivenAsAnHttpDate(): void
    {
        $retryAfter = gmdate('D, d M Y H:i:s', self::NOW + 90) . ' GMT';

        self::assertSame(90, RateLimitInfo::fromHeaders(['retry-after' => $retryAfter], self::NOW)?->retryAfterSeconds);
    }

    #[Test]
    public function itTreatsARetryAfterDateInThePastAsNoWait(): void
    {
        $retryAfter = gmdate('D, d M Y H:i:s', self::NOW - 90) . ' GMT';

        self::assertSame(0, RateLimitInfo::fromHeaders(['retry-after' => $retryAfter], self::NOW)?->retryAfterSeconds);
    }

    #[Test]
    public function itIgnoresValuesItCannotRead(): void
    {
        $info = RateLimitInfo::fromHeaders(['ratelimit-limit' => 'many', 'retry-after' => 'soon-ish!'], self::NOW);

        self::assertNull($info);
    }

    #[Test]
    public function itIsAbsentWithoutRateLimitHeaders(): void
    {
        self::assertNull(RateLimitInfo::fromHeaders(['content-type' => 'application/json'], self::NOW));
    }
}
