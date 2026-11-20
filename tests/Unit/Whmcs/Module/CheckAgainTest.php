<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Module;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Tests\Support\WhmcsFake;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use WHMCS\Database\Capsule;

/**
 * The client area's "Check again", the one module function a customer can run.
 */
final class CheckAgainTest extends ModuleTestCase
{
    private const LIMIT_KEY = 'dns-check:' . self::SERVICE_ID;
    private const CHECK_LATER = 'You can check again in a minute.';
    private const CHECK_FAILED = 'We could not check your portal\'s address right now. Please try again later.';
    private const LOGGED = 'Sign.net: "Check again" on service #101 failed: ';

    private ?string $secondAnswer = null;

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

    /**
     * @return iterable<string, array{int}>
     */
    public static function momentsASecondRequestArrives(): iterable
    {
        yield 'as the first starts on the minute' => [1];
        yield 'as the first takes the minute' => [2];
    }

    #[Test]
    #[DataProvider('momentsASecondRequestArrives')]
    public function twoRequestsAtOnceCheckOnlyOnce(int $statement): void
    {
        $this->queueToken();
        $this->transport->queueData(R::label());
        $this->sendASecondRequestBefore($statement);

        $first = signnet_checkDomain($this->params());

        self::assertNotNull($this->secondAnswer, 'The second request never arrived.');
        self::assertEqualsCanonicalizing(['success', self::CHECK_LATER], [$first, $this->secondAnswer]);
        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABEL], $this->calls());
    }

    #[Test]
    public function aMinuteLaterItChecksAgain(): void
    {
        $this->queueToken();
        $this->transport->queueData(R::label());
        $this->transport->queueData(R::label());
        signnet_checkDomain($this->params());
        Capsule::table(Schema::CACHE)->where('cache_key', self::LIMIT_KEY)->update(['expires_at' => time() - 1]);

        self::assertSame('success', signnet_checkDomain($this->params()));
        self::assertSame([...self::FIRST_CALLS, 'GET ' . R::LABEL, 'GET ' . R::LABEL], $this->calls());
    }

    #[Test]
    public function aCheckThatCannotReachSignNetIsToldInPlainWords(): void
    {
        $this->queueToken();
        $lost = new TransportException(
            'GET https://' . self::API_HOST . R::LABEL . ' got no response: cURL error 28: Operation timed out',
            true,
            'req-1',
        );
        $this->transport->queue($lost, $lost, $lost);

        $answer = signnet_checkDomain($this->params());

        self::assertSame(self::CHECK_FAILED, $answer);
        self::assertStringNotContainsString('Sign.net', $answer);
        self::assertSame([[
            'message' => self::LOGGED . 'Could not reach Sign.net: GET https://' . self::API_HOST . R::LABEL
                . ' got no response: cURL error 28: Operation timed out (request req-1)',
            'userId' => self::CLIENT_ID,
        ]], WhmcsFake::$activity);
        $activity = implode("\n", WhmcsFake::activityMessages());
        self::assertStringNotContainsString(self::API_SECRET, $activity);
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $activity);
    }

    #[Test]
    public function aDatabaseFailureIsToldInPlainWords(): void
    {
        Schema::ensure();
        Capsule::schema()->drop(Schema::TOKENS);

        self::assertSame(self::CHECK_FAILED, signnet_checkDomain($this->params()));
        self::assertStringStartsWith(self::LOGGED . 'SQLSTATE', WhmcsFake::activityMessages()[0]);
        self::assertStringContainsString('no such table: ' . Schema::TOKENS, WhmcsFake::activityMessages()[0]);
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function aPortalDeletedOutsideWhmcsIsToldInPlainWords(): void
    {
        $this->queueToken();
        $this->transport->queueError(403, 'forbidden');
        $this->transport->queueData(R::dashboard());
        $this->transport->queueData(R::labels());

        self::assertSame(self::CHECK_FAILED, signnet_checkDomain($this->params()));
        self::assertSame(
            [self::LOGGED . 'The portal sign.acme.test no longer exists in Sign.net; it was deleted outside WHMCS. '
                . 'Unlink it, or terminate the service.'],
            WhmcsFake::activityMessages(),
        );
    }

    #[Test]
    public function aServiceWithoutAPortalIsToldInPlainWords(): void
    {
        $this->changeLink(['tenantId' => null, 'state' => ServiceLink::STATE_UNLINKED]);

        self::assertSame(self::CHECK_FAILED, signnet_checkDomain($this->params()));
        self::assertSame([self::LOGGED . 'This service has no Sign.net portal.'], WhmcsFake::activityMessages());
        self::assertSame([], $this->calls());
    }

    #[Test]
    public function aCheckThatFailedStillCountsAgainstTheMinute(): void
    {
        $this->queueToken();
        $this->transport->queueError(503, 'unavailable');
        $this->transport->queueError(503, 'unavailable');
        $this->transport->queueError(503, 'unavailable');
        signnet_checkDomain($this->params());
        $calls = $this->calls();

        self::assertSame(self::CHECK_LATER, signnet_checkDomain($this->params()));
        self::assertSame($calls, $this->calls());
        self::assertCount(1, WhmcsFake::activityMessages());
    }

    #[Test]
    public function aFailedCheckLeavesWhatTheServiceLastReportedAlone(): void
    {
        $this->changeLink(['lastError' => 'That portal address is already in use on Sign.net.']);
        $this->queueToken();
        $this->transport->queueError(429, 'too_many_requests', ['Retry-After' => '30']);

        signnet_checkDomain($this->params());

        self::assertSame(
            'That portal address is already in use on Sign.net.',
            (new ServiceRepository())->find(self::SERVICE_ID)?->lastError,
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function changeLink(array $changes): void
    {
        $links = new ServiceRepository();
        $link = $links->find(self::SERVICE_ID);
        self::assertNotNull($link);
        $links->save($link->with($changes));
    }

    /**
     * Runs a second "Check again" for the service just before the database runs the $statement-th
     * statement about its once-a-minute limit, as a request arriving at that moment would.
     */
    private function sendASecondRequestBefore(int $statement): void
    {
        $seen = 0;
        Capsule::connection()->beforeExecuting(
            /** @param array<mixed> $bindings */
            function (string $query, array $bindings) use ($statement, &$seen): void {
                if (in_array(self::LIMIT_KEY, $bindings, true) && ++$seen === $statement) {
                    $this->secondAnswer = signnet_checkDomain($this->params());
                }
            },
        );
    }
}
