<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

use SignNet\ResellerApi\Model\ItemCode;
use SignNet\ResellerApi\Model\Quota;
use SignNet\ResellerApi\Model\QuotaItem;
use SignNet\ResellerApi\Model\QuotaPlan;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Support\Html;

/**
 * The reseller's Sign.net plan and what is left of each allowance item.
 */
final class QuotaView
{
    private const OUTSIDE_PACKAGES_HELP = 'Used outside packages is what your own team used, and what private labels '
        . 'used beyond their packages. Sign.net takes it from your allowance, as it does an allocation.';

    /**
     * @param int $lowPercent Below this share of an item's allowance left, the item is flagged.
     */
    public static function render(Quota $quota, int $lowPercent): string
    {
        if ($quota->plan === null) {
            return AdminHtml::alert(
                'warning',
                'Sign.net has not put your account on a plan yet — packages cannot be assigned.',
            );
        }
        $rows = array_map(
            static fn (string $itemCode): array => self::row($itemCode, $quota->item($itemCode), $lowPercent),
            ItemCode::ALL,
        );

        $headings = ['Item', 'Included', 'Allocated now', 'Used outside packages', 'Remaining', 'Used', ''];

        return '<p>' . Html::escape(self::planLine($quota->plan, $quota->windowFrom, $quota->windowTo)) . '</p>'
            . Html::table($headings, $rows, 'table table-striped')
            . '<p class="help-block">' . Html::escape(self::OUTSIDE_PACKAGES_HELP) . '</p>';
    }

    private static function planLine(QuotaPlan $plan, ?int $windowFrom, ?int $windowTo): string
    {
        $line = sprintf('Plan: %s (%s).', $plan->packageName, CatalogueLabels::cycle($plan->billingCycle));
        if ($windowFrom === null || $windowTo === null) {
            return $line;
        }

        return $line . sprintf(' Billing window: %s to %s (UTC).', self::date($windowFrom), self::date($windowTo));
    }

    /**
     * @return list<string>
     */
    private static function row(string $itemCode, ?QuotaItem $item, int $lowPercent): array
    {
        $label = Html::escape(CatalogueLabels::item($itemCode));
        if ($item === null) {
            return [$label, '—', '—', '—', '—', '—', Html::escape('Not in your plan')];
        }

        return [
            $label,
            Html::escape($item->includedQty),
            Html::escape($item->allocatedNow),
            Html::escape($item->usedOutsideAllocations()),
            Html::escape($item->remaining),
            Html::escape($item->used),
            self::flag($item, $lowPercent),
        ];
    }

    private static function flag(QuotaItem $item, int $lowPercent): string
    {
        if ($item->overAllocatedBy > 0) {
            return self::label('danger', sprintf('Over-allocated by %d', $item->overAllocatedBy));
        }
        if ($item->includedQty > 0 && $item->remaining * 100 < $item->includedQty * $lowPercent) {
            return self::label('warning', sprintf('Low: less than %d%% left', $lowPercent));
        }

        return '';
    }

    private static function label(string $level, string $text): string
    {
        return '<span class="label label-' . $level . '">' . Html::escape($text) . '</span>';
    }

    private static function date(int $milliseconds): string
    {
        return gmdate('Y-m-d', intdiv($milliseconds, 1000));
    }
}
