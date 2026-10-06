<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Provisioning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Model\Addon;
use SignNet\ResellerApi\Model\Package;
use SignNet\ResellerApi\Model\Quota;
use SignNet\Tests\Support\SignNetResponses as R;
use SignNet\Whmcs\Provisioning\AllowancePreCheck;

final class AllowancePreCheckTest extends TestCase
{
    #[Test]
    public function itAddsThePackageAndEveryUnitOfEachAddon(): void
    {
        $requested = AllowancePreCheck::requested(
            Package::fromArray(R::package('pkg-1', ['seats' => 10, 'documents' => 500])),
            [[Addon::fromArray(R::addon('add-1', 'SEATS5', ['seats' => 5])), 3]],
        );

        self::assertSame(['seats' => 25, 'documents' => 500], $requested);
    }

    #[Test]
    public function itReportsOnlyItemsThatDoNotFit(): void
    {
        $quota = Quota::fromArray(R::quota(['seats' => 20, 'documents' => 100]));

        $shortfalls = AllowancePreCheck::shortfalls($quota, ['seats' => 20, 'documents' => 150, 'templates' => 1]);

        self::assertSame(
            ['documents: 150 needed, 100 left (50 over)', 'templates: 1 needed, 0 left (1 over)'],
            array_map(AllowancePreCheck::describe(...), $shortfalls),
        );
    }
}
