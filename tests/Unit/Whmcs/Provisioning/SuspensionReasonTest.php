<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\Whmcs\Provisioning\SuspensionReason;

final class SuspensionReasonTest extends TestCase
{
    #[Test]
    public function itTrimsUnicodeSpacesAsWellAsAsciiOnes(): void
    {
        $padded = "\u{3000}\u{FEFF} Overdue on Payment\u{00A0}\t\n";

        self::assertSame('Overdue on Payment', SuspensionReason::trimmed($padded));
        self::assertSame("Overdue\u{00A0}on Payment", SuspensionReason::trimmed("Overdue\u{00A0}on Payment\u{2028}"));
        self::assertSame('', SuspensionReason::trimmed(" \u{00A0}\f"));
    }

    #[Test]
    public function aReasonCutAfterAWhitespaceLosesItToo(): void
    {
        $sent = SuspensionReason::fromWhmcs(str_repeat('a', 499) . ' and the rest');

        self::assertSame(str_repeat('a', 499), $sent);
    }

    #[Test]
    public function aReasonThatFitsIsSentAsTyped(): void
    {
        self::assertSame('Abuse: phishing', SuspensionReason::fromWhmcs('  Abuse: phishing '));
        self::assertSame(str_repeat("\u{1F600}", 250), SuspensionReason::fromWhmcs(str_repeat("\u{1F600}", 400)));
    }
}
