<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Http\Response;

final class ResponseTest extends TestCase
{
    #[Test]
    public function itLowerCasesHeaderNames(): void
    {
        $response = new Response(200, ['RateLimit-Remaining' => '3', 'Content-Type' => 'application/json'], '{}');

        self::assertSame(['ratelimit-remaining' => '3', 'content-type' => 'application/json'], $response->headers);
        self::assertSame('3', $response->header('RATELIMIT-REMAINING'));
    }

    #[Test]
    public function itDecodesAJsonObject(): void
    {
        self::assertSame(['a' => [1, 2]], (new Response(200, [], '{"a":[1,2]}'))->json());
    }

    #[Test]
    public function itRefusesABodyThatIsNotJson(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new Response(502, [], '<html>Bad Gateway</html>'))->json();
    }

    #[Test]
    public function itRefusesAJsonScalar(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new Response(200, [], '"ok"'))->json();
    }

    #[Test]
    public function itCountsOnlyTwoHundredsAsSuccess(): void
    {
        self::assertTrue((new Response(201, [], ''))->isSuccessful());
        self::assertFalse((new Response(302, [], ''))->isSuccessful());
        self::assertFalse((new Response(199, [], ''))->isSuccessful());
    }
}
