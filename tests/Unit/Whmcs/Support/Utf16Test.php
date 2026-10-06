<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\Whmcs\Support\Utf16;

final class Utf16Test extends TestCase
{
    #[Test]
    public function itCountsAnEmojiAsTwoUnitsAsSignNetDoes(): void
    {
        self::assertSame(str_repeat("\u{1F600}", 2), Utf16::truncate(str_repeat("\u{1F600}", 3), 5));
        self::assertSame('ab', Utf16::truncate("ab\u{1F600}", 3));
    }

    #[Test]
    public function itKeepsTextThatFitsAndCutsOnlyAtCharacters(): void
    {
        self::assertSame('Zoë Ågren', Utf16::truncate('Zoë Ågren', 9));
        self::assertSame('Zoë', Utf16::truncate('Zoë Ågren', 3));
        self::assertSame('', Utf16::truncate('', 10));
    }
}
