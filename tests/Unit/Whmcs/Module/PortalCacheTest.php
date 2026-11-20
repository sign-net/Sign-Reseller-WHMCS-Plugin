<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Module;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Whmcs\Db\Schema;
use WHMCS\Database\Capsule;

/**
 * How often the client area and the service page ask Sign.net about a portal, above all while
 * reading it fails: every order this WHMCS sends shares the same rate limits.
 */
final class PortalCacheTest extends ModuleTestCase
{
    private const READ = 'GET ' . R::LABEL;
    private const GONE_CALLS = [self::READ, R::DASHBOARD_CALL, 'GET ' . R::LABELS];
    private const GONE = 'The portal sign.acme.test no longer exists in Sign.net; it was deleted outside WHMCS. '
        . 'Unlink it, or terminate the service.';
    private const DAY = 86_400;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../../modules/servers/signnet/signnet.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertService(self::SERVICE_ID, 'Active');
        $this->linkPortal();
    }

    #[Test]
    public function aPortalDeletedOutsideWhmcsIsLookedForOnceADay(): void
    {
        $this->queueToken();
        $this->queueGone();

        $this->openThePagesAgainAndAgain();
        $this->secondsPass(self::DAY - 10);
        $this->openThePagesAgainAndAgain();

        self::assertSame([...self::FIRST_CALLS, ...self::GONE_CALLS], $this->calls());
        self::assertTrue($this->clientArea()['loadFailed']);
        self::assertStringContainsString(self::GONE, signnet_AdminServicesTabFields($this->params())['Portal status']);

        $this->secondsPass(20);
        $this->queueGone();
        $this->openThePagesAgainAndAgain();

        self::assertSame([...self::FIRST_CALLS, ...self::GONE_CALLS, ...self::GONE_CALLS], $this->calls());
    }

    /**
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function rateLimits(): iterable
    {
        yield 'Retry-After of two minutes' => [['Retry-After' => '120'], 120];
        yield 'no Retry-After' => [[], 60];
        yield 'Retry-After under a minute' => [['Retry-After' => '5'], 60];
        yield 'Retry-After of a day' => [['Retry-After' => '86400'], 3_600];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('rateLimits')]
    public function aRateLimitedReadWaitsAsLongAsSignNetAsksWithinReason(array $headers, int $wait): void
    {
        $this->queueToken();
        $this->transport->queueError(429, 'too_many_requests', $headers);

        $this->openThePagesAgainAndAgain();
        $this->secondsPass($wait - 10);
        $this->openThePagesAgainAndAgain();

        self::assertSame([...self::FIRST_CALLS, self::READ], $this->calls());

        $this->secondsPass(20);
        $this->transport->queueData(R::label());
        $this->openThePagesAgainAndAgain();

        self::assertSame([...self::FIRST_CALLS, self::READ, self::READ], $this->calls());
        self::assertFalse($this->clientArea()['loadFailed']);
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function failedReads(): iterable
    {
        yield 'a server error' => [503];
        yield 'no answer' => [null];
    }

    #[Test]
    #[DataProvider('failedReads')]
    public function aReadThatFailedIsNotRepeatedForAMinute(?int $status): void
    {
        $this->queueToken();
        $this->queueFailedRead($status);

        $this->openThePagesAgainAndAgain();
        $this->secondsPass(50);
        $this->openThePagesAgainAndAgain();

        self::assertSame([...self::FIRST_CALLS, self::READ, self::READ, self::READ], $this->calls());

        $this->secondsPass(20);
        $this->transport->queueData(R::label());
        $this->openThePagesAgainAndAgain();

        self::assertSame([...self::FIRST_CALLS, self::READ, self::READ, self::READ, self::READ], $this->calls());
    }

    #[Test]
    public function aRefusalForAnAdministratorToFixIsAskedAboutAgain(): void
    {
        $this->queueToken();
        $this->transport->queueError(403, 'insufficient_scope');
        $this->transport->queueData(R::label());

        signnet_AdminServicesTabFields($this->params());
        $fields = signnet_AdminServicesTabFields($this->params());

        self::assertSame('Ann Lee &lt;owner@acme.test&gt;', $fields['Owner']);
        self::assertSame([...self::FIRST_CALLS, self::READ, self::READ], $this->calls());
    }

    #[Test]
    public function theServicePageExplainsARememberedFailureWithoutAskingAgain(): void
    {
        $this->queueToken();
        $this->transport->queueError(429, 'too_many_requests', ['Retry-After' => '120']);
        $this->clientArea();

        $status = signnet_AdminServicesTabFields($this->params())['Portal status'];
        $invite = signnet_resendOwnerInvite($this->params());

        $rateLimited = 'Sign.net is rate limiting these requests; try again in 120 seconds. (request ';
        self::assertStringContainsString('Unavailable', $status);
        self::assertStringContainsString($rateLimited, $status);
        self::assertStringStartsWith($rateLimited, $invite);
        self::assertSame([...self::FIRST_CALLS, self::READ], $this->calls());
    }

    #[Test]
    public function refreshFromSignNetAsksAtOnceAndForgetsTheFailure(): void
    {
        $this->queueToken();
        $this->queueGone();
        $this->clientArea();
        $this->transport->queueData(R::label());
        $this->transport->queueData(R::label());

        self::assertSame('success', signnet_refresh($this->params()));

        self::assertFalse($this->clientArea()['loadFailed']);
        self::assertSame([...self::FIRST_CALLS, ...self::GONE_CALLS, self::READ, self::READ], $this->calls());
    }

    #[Test]
    public function unlinkingAndLinkingAgainAsksAtOnce(): void
    {
        $this->queueToken();
        $this->transport->queueError(429, 'too_many_requests', ['Retry-After' => '600']);
        $this->clientArea();
        $this->transport->queueData(R::labels([R::TENANT_ID => R::HOST]));
        $this->transport->queueData(R::label());
        $this->transport->queueData(R::label());

        self::assertSame('success', signnet_unlinkPortal($this->params()));
        self::assertSame('success', signnet_linkPortal($this->params(['username' => R::TENANT_ID])));

        self::assertFalse($this->clientArea()['loadFailed']);
        self::assertSame(
            [...self::FIRST_CALLS, self::READ, 'GET ' . R::LABELS, self::READ, self::READ],
            $this->calls(),
            'Linking reads the portal to name its owner, and the next view reads it again.',
        );
    }

    #[Test]
    public function checkAgainAsksSignNetWhateverIsRemembered(): void
    {
        $this->queueToken();
        $this->transport->queueError(429, 'too_many_requests', ['Retry-After' => '600']);
        $this->clientArea();
        $this->transport->queueData(R::label());

        self::assertSame('success', signnet_checkDomain($this->params()));
        self::assertSame([...self::FIRST_CALLS, self::READ, self::READ], $this->calls());
    }

    private function queueGone(): void
    {
        $this->transport->queueError(403, 'forbidden');
        $this->transport->queueData(R::dashboard());
        $this->transport->queueData(R::labels());
    }

    /**
     * A read Sign.net fails, every attempt of it: one, and the two retries a read is given.
     */
    private function queueFailedRead(?int $status): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($status === null) {
                $this->transport->queue(new TransportException('Connection refused', false, 'req-' . $attempt));
            } else {
                $this->transport->queueError($status, 'unavailable');
            }
        }
    }

    private function openThePagesAgainAndAgain(): void
    {
        for ($view = 0; $view < 3; $view++) {
            $this->clientArea();
            signnet_AdminServicesTabFields($this->params());
        }
    }

    /**
     * @return array<string, mixed> The client area's template variables.
     */
    private function clientArea(): array
    {
        $variables = signnet_ClientArea($this->params())['templateVariables'];
        self::assertIsArray($variables);
        self::assertIsArray($variables['signnet']);

        return $variables['signnet'];
    }

    /**
     * Brings everything the plugin cached $seconds closer to expiring, as that much time passing would.
     */
    private function secondsPass(int $seconds): void
    {
        Capsule::table(Schema::CACHE)->decrement('expires_at', $seconds);
    }
}
