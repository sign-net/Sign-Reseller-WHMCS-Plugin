<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Http\Request;

final class RequestTest extends TestCase
{
    #[Test]
    public function itFindsHeadersWhateverTheirCase(): void
    {
        $request = new Request('GET', 'https://api.test/x', ['X-Request-Id' => 'abc'], null, 30);

        self::assertSame('abc', $request->header('x-request-id'));
        self::assertNull($request->header('Authorization'));
    }

    #[Test]
    public function itReplacesAHeaderWithoutTouchingTheOriginal(): void
    {
        $headers = ['authorization' => 'Bearer old', 'Accept' => 'a'];
        $request = new Request('GET', 'https://api.test/x', $headers, null, 30);

        $replaced = $request->withHeader('Authorization', 'Bearer new');

        self::assertSame(['Accept' => 'a', 'Authorization' => 'Bearer new'], $replaced->headers);
        self::assertSame('Bearer old', $request->header('Authorization'));
    }

    #[Test]
    public function aReadOnlyRequestStaysReadOnlyWhenAHeaderIsReplaced(): void
    {
        $request = new Request('POST', 'https://api.test/x', [], '{}', 30, 10, true);

        self::assertTrue($request->withHeader('Authorization', 'Bearer new')->isReadOnly);
    }

    #[Test]
    public function itNamesTheCallByPathAndQuery(): void
    {
        $request = new Request('GET', 'https://api.test/console/acme.test/reseller/usage?period=2026-07', [], null, 30);

        self::assertSame('/console/acme.test/reseller/usage?period=2026-07', $request->target());
        self::assertSame('/', (new Request('GET', 'https://api.test', [], null, 30))->target());
    }

    #[Test]
    public function itMasksCredentialsWhenDumped(): void
    {
        $request = new Request(
            'POST',
            'https://api.test/api/v1/auth/token',
            ['Authorization' => 'Bearer top.secret.jwt'],
            '{"grant_type":"client_credentials","api_key":"snk_live_0123_Hush"}',
            30,
        );

        $dump = print_r($request, true);

        self::assertStringNotContainsString('top.secret.jwt', $dump);
        self::assertStringNotContainsString('Hush', $dump);
        self::assertStringContainsString('client_credentials', $dump);
    }
}
