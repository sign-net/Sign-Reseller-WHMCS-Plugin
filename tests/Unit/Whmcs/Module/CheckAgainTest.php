<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Module;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\ModuleTestCase;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Whmcs\Db\Schema;
use WHMCS\Database\Capsule;

/**
 * The client area's "Check again", the one module function a customer can run.
 */
final class CheckAgainTest extends ModuleTestCase
{
    private const LIMIT_KEY = 'dns-check:' . self::SERVICE_ID;
    private const CHECK_LATER = 'You can check again in a minute.';

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
