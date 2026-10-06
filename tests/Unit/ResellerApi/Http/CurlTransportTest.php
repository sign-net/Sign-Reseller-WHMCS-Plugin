<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Http;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Http\CurlTransport;
use SignNet\ResellerApi\Http\Request;

/**
 * Runs CurlTransport against PHP's built-in server with tests/Fixtures/curl-router.php.
 */
#[Group('network-local')]
final class CurlTransportTest extends TestCase
{
    /**
     * @var resource|null
     */
    private static $server = null;
    private static ?int $port = null;
    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::reserveFreePort();
        if ($port === null) {
            self::$skipReason = 'No local TCP port could be reserved.';

            return;
        }

        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 3) . '/Fixtures/curl-router.php'],
            [0 => ['file', $nullDevice, 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
        );
        if (!is_resource($server)) {
            self::$skipReason = 'The PHP built-in server could not be started.';

            return;
        }
        self::$server = $server;

        if (!self::waitUntilListening($port)) {
            self::$skipReason = sprintf('The PHP built-in server did not listen on port %d.', $port);

            return;
        }
        self::$port = $port;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        self::$port = null;
    }

    protected function setUp(): void
    {
        if (self::$port === null) {
            self::markTestSkipped(self::$skipReason);
        }
    }

    #[Test]
    public function itRoundTripsStatusHeadersAndBody(): void
    {
        $response = (new CurlTransport())->send(new Request(
            'POST',
            $this->url('/echo?status=201'),
            ['Content-Type' => 'application/json', 'X-Request-Id' => 'request-1'],
            '{"hello":"world"}',
            5,
            2,
        ));

        self::assertSame(201, $response->status);
        self::assertSame('POST', $response->header('X-Echo-Method'));
        self::assertSame('first, second', $response->headers['x-multi']);
        $echo = $response->json();
        self::assertSame('/echo', $echo['path']);
        self::assertSame('{"hello":"world"}', $echo['body']);
        $headers = $echo['headers'];
        self::assertIsArray($headers);
        self::assertSame('request-1', $headers['X-Request-Id']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertArrayNotHasKey('Expect', $headers);
    }

    #[Test]
    public function itSendsALargeBodyWithoutWaitingForContinue(): void
    {
        $body = json_encode(['padding' => str_repeat('x', 20_000)], JSON_THROW_ON_ERROR);

        $response = (new CurlTransport())->send(new Request('PATCH', $this->url('/echo'), [], $body, 5, 2));

        self::assertSame(200, $response->status);
        self::assertSame($body, $response->json()['body']);
        self::assertSame('PATCH', $response->header('x-echo-method'));
    }

    #[Test]
    public function itSendsAGetWithoutABody(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', $this->url('/echo?status=404'), [], null, 5, 2));

        self::assertSame(404, $response->status);
        self::assertSame('', $response->json()['body']);
    }

    #[Test]
    public function itReportsAConnectFailureAsNeverSent(): void
    {
        $closedPort = self::reserveFreePort();
        if ($closedPort === null) {
            self::markTestSkipped('No local TCP port could be reserved.');
        }

        try {
            (new CurlTransport())->send(new Request(
                'POST',
                'http://127.0.0.1:' . $closedPort . '/console/reseller.sign.test/reseller/private-labels',
                ['X-Request-Id' => 'request-2'],
                '{}',
                5,
                2,
            ));
            self::fail('A request to a closed port succeeded.');
        } catch (TransportException $exception) {
            self::assertFalse($exception->requestSent);
            self::assertSame('request-2', $exception->requestId);
            self::assertNull($exception->httpStatus);
        }
    }

    #[Test]
    public function itReportsATimeoutAfterSendingAsSent(): void
    {
        try {
            (new CurlTransport())->send(new Request('POST', $this->url('/echo?sleep=3'), [], '{}', 1, 1));
            self::fail('The request did not time out.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->requestSent);
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    private static function reserveFreePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return null;
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($name === false) {
            return null;
        }
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        return $port > 0 ? $port : null;
    }

    private static function waitUntilListening(int $port): bool
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return true;
            }
            usleep(50_000);
        }

        return false;
    }
}
