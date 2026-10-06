<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use SignNet\ResellerApi\Model\BillingCycle;

/**
 * Sign.net prices (integer minor units and a billing cycle) as WHMCS prices them: two-decimal
 * strings in the cycle columns of tblpricing.
 */
final class PriceMapper
{
    /** WHMCS's recurring pricing columns, each with the months it covers and its setup fee column. */
    private const COLUMNS = [
        'monthly' => ['months' => 1, 'setupFee' => 'msetupfee'],
        'quarterly' => ['months' => 3, 'setupFee' => 'qsetupfee'],
        'semiannually' => ['months' => 6, 'setupFee' => 'ssetupfee'],
        'annually' => ['months' => 12, 'setupFee' => 'asetupfee'],
        'biennially' => ['months' => 24, 'setupFee' => 'bsetupfee'],
        'triennially' => ['months' => 36, 'setupFee' => 'tsetupfee'],
    ];

    /** WHMCS keeps a one-time price in the monthly column. */
    private const COLUMN_BY_CYCLE = [
        BillingCycle::MONTHLY => 'monthly',
        BillingCycle::QUARTERLY => 'quarterly',
        BillingCycle::BIANNUAL => 'semiannually',
        BillingCycle::ANNUAL => 'annually',
        BillingCycle::ONE_TIME => 'monthly',
    ];

    /** How WHMCS marks a billing cycle a product does not offer. */
    private const NOT_OFFERED = '-1.00';
    private const FREE = '0.00';
    private const DECIMAL = '/^(\d{1,12})(?:\.(\d{1,2}))?$/D';

    /**
     * @throws \InvalidArgumentException When the cycle is not a BillingCycle value.
     */
    public static function column(string $billingCycle): string
    {
        return self::COLUMN_BY_CYCLE[$billingCycle]
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a Sign.net billing cycle.', $billingCycle));
    }

    /**
     * The product's WHMCS payment type: "onetime" or "recurring".
     *
     * @throws \InvalidArgumentException When the cycle is not a BillingCycle value.
     */
    public static function payType(string $billingCycle): string
    {
        self::column($billingCycle);

        return $billingCycle === BillingCycle::ONE_TIME ? 'onetime' : 'recurring';
    }

    public static function toDecimal(int $minor): string
    {
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * "19.9" becomes 1990; null when the text is not an amount of at most two decimals.
     */
    public static function toMinor(string $decimal): ?int
    {
        if (preg_match(self::DECIMAL, trim($decimal), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    /**
     * A product's pricing: the price in its cycle's column, every other cycle not offered, no setup fees.
     *
     * @return array<string, string> tblpricing column => amount.
     *
     * @throws \InvalidArgumentException When the cycle is not a BillingCycle value.
     */
    public static function productPricing(string $billingCycle, int $priceMinor): array
    {
        $offered = self::column($billingCycle);
        $pricing = [];
        foreach (self::COLUMNS as $column => $cycle) {
            $pricing[$cycle['setupFee']] = self::FREE;
            $pricing[$column] = $column === $offered ? self::toDecimal($priceMinor) : self::NOT_OFFERED;
        }

        return $pricing;
    }

    /**
     * A configurable option's price per unit in every cycle, so the option costs the same per month
     * whatever cycle the product is billed on. A one-time price fills the monthly column only.
     *
     * @return array<string, string> tblpricing column => amount.
     *
     * @throws \InvalidArgumentException When the cycle is not a BillingCycle value.
     */
    public static function configOptionPricing(string $billingCycle, int $unitPriceMinor): array
    {
        $ownColumn = self::column($billingCycle);
        $ownMonths = self::COLUMNS[$ownColumn]['months'];
        $isOneTime = $billingCycle === BillingCycle::ONE_TIME;
        $pricing = [];
        foreach (self::COLUMNS as $column => $cycle) {
            $pricing[$cycle['setupFee']] = self::FREE;
            $pricing[$column] = match (true) {
                $column === $ownColumn => self::toDecimal($unitPriceMinor),
                $isOneTime => self::FREE,
                default => self::toDecimal((int) round($unitPriceMinor * $cycle['months'] / $ownMonths)),
            };
        }

        return $pricing;
    }
}
