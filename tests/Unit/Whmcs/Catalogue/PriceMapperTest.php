<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Catalogue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Model\BillingCycle;
use SignNet\Whmcs\Catalogue\PriceMapper;

final class PriceMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cycles(): iterable
    {
        yield 'monthly' => [BillingCycle::MONTHLY, 'monthly', 'recurring'];
        yield 'quarterly' => [BillingCycle::QUARTERLY, 'quarterly', 'recurring'];
        yield 'every six months' => [BillingCycle::BIANNUAL, 'semiannually', 'recurring'];
        yield 'annually' => [BillingCycle::ANNUAL, 'annually', 'recurring'];
        yield 'one time' => [BillingCycle::ONE_TIME, 'monthly', 'onetime'];
    }

    #[Test]
    #[DataProvider('cycles')]
    public function itMapsEachSignNetCycleToAWhmcsPricingColumn(string $cycle, string $column, string $payType): void
    {
        self::assertSame($column, PriceMapper::column($cycle));
        self::assertSame($payType, PriceMapper::payType($cycle));
    }

    #[Test]
    public function itRefusesACycleSignNetDoesNotHave(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PriceMapper::column('Weekly');
    }

    #[Test]
    public function itWritesMinorUnitsWithTwoDecimals(): void
    {
        self::assertSame('19.00', PriceMapper::toDecimal(1900));
        self::assertSame('0.05', PriceMapper::toDecimal(5));
        self::assertSame('1234.56', PriceMapper::toDecimal(123456));
        self::assertSame('-1.50', PriceMapper::toDecimal(-150));
    }

    #[Test]
    public function itReadsAmountsTypedIntoAForm(): void
    {
        self::assertSame(1990, PriceMapper::toMinor('19.9'));
        self::assertSame(1900, PriceMapper::toMinor(' 19 '));
        self::assertSame(5, PriceMapper::toMinor('0.05'));
        self::assertNull(PriceMapper::toMinor('19.999'));
        self::assertNull(PriceMapper::toMinor('-1'));
        self::assertNull(PriceMapper::toMinor('1,50'));
        self::assertNull(PriceMapper::toMinor(''));
    }

    #[Test]
    public function itOffersAProductOnlyInItsPackagesCycle(): void
    {
        $pricing = PriceMapper::productPricing(BillingCycle::ANNUAL, 12000);

        self::assertSame([
            'msetupfee' => '0.00',
            'monthly' => '-1.00',
            'qsetupfee' => '0.00',
            'quarterly' => '-1.00',
            'ssetupfee' => '0.00',
            'semiannually' => '-1.00',
            'asetupfee' => '0.00',
            'annually' => '120.00',
            'bsetupfee' => '0.00',
            'biennially' => '-1.00',
            'tsetupfee' => '0.00',
            'triennially' => '-1.00',
        ], $pricing);
    }

    #[Test]
    public function itChargesAnAddonUnitTheSamePerMonthOnEveryCycle(): void
    {
        $fromMonthly = PriceMapper::configOptionPricing(BillingCycle::MONTHLY, 500);
        $fromAnnual = PriceMapper::configOptionPricing(BillingCycle::ANNUAL, 6000);

        $expected = ['monthly' => '5.00', 'quarterly' => '15.00', 'semiannually' => '30.00', 'annually' => '60.00',
            'biennially' => '120.00', 'triennially' => '180.00'];
        self::assertSame($expected, array_intersect_key($fromMonthly, $expected));
        self::assertSame($expected, array_intersect_key($fromAnnual, $expected));
        self::assertSame('0.00', $fromMonthly['msetupfee']);
    }

    #[Test]
    public function itRoundsAScaledUnitPriceToTheNearestMinorUnit(): void
    {
        $pricing = PriceMapper::configOptionPricing(BillingCycle::QUARTERLY, 1000);

        self::assertSame('3.33', $pricing['monthly']);
        self::assertSame('10.00', $pricing['quarterly']);
        self::assertSame('20.00', $pricing['semiannually']);
    }

    #[Test]
    public function itPutsAOneTimeAddonPriceInTheMonthlyColumnOnly(): void
    {
        $pricing = PriceMapper::configOptionPricing(BillingCycle::ONE_TIME, 2500);

        self::assertSame('25.00', $pricing['monthly']);
        self::assertSame('0.00', $pricing['annually']);
        self::assertSame('0.00', $pricing['triennially']);
    }
}
