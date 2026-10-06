<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Addons;

use SignNet\ResellerApi\Model\AddonSummary;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Admin\CatalogueLimit;
use SignNet\Whmcs\Catalogue\PriceMapper;
use SignNet\Whmcs\Support\Html;

/**
 * The reseller's add-ons, with how much of Sign.net's cap they use.
 */
final class AddonTable
{
    /**
     * @param list<AddonSummary> $addons
     */
    public static function render(AdminRequest $request, array $addons): string
    {
        $archived = count(array_filter($addons, static fn (AddonSummary $addon): bool => !$addon->isActive));
        $html = '<p>' . Html::escape(CatalogueLimit::describe(count($addons), $archived, 'add-ons')) . '</p>'
            . '<p>' . (CatalogueLimit::isFull(count($addons))
                ? Html::escape('Your add-on catalogue is full.')
                : AdminHtml::link($request->url('addons', ['action' => 'create']), 'New add-on', 'btn btn-primary'))
            . '</p>';
        if ($addons === []) {
            return $html . '<p>' . Html::escape('You have no add-ons yet.') . '</p>';
        }
        $rows = array_map(static fn (AddonSummary $addon): array => self::row($request, $addon), $addons);

        return $html
            . Html::table(['Code', 'Name', 'Cycle', 'Price per unit', 'Status', ''], $rows, 'table table-striped');
    }

    /**
     * @return list<string>
     */
    private static function row(AdminRequest $request, AddonSummary $addon): array
    {
        return [
            '<code>' . Html::escape($addon->code) . '</code>',
            Html::escape($addon->name),
            Html::escape(CatalogueLabels::cycle($addon->billingCycle)),
            Html::escape(PriceMapper::toDecimal($addon->priceMinor) . ' ' . $addon->currency),
            $addon->isActive
                ? '<span class="label label-success">Active</span>'
                : '<span class="label label-default">Archived</span>',
            self::actions($request, $addon),
        ];
    }

    private static function actions(AdminRequest $request, AddonSummary $addon): string
    {
        $id = ['id' => $addon->addonId];

        return AdminHtml::link($request->url('addons', ['action' => 'edit'] + $id), 'Edit', 'btn btn-default btn-sm')
            . ' ' . AdminHtml::link(
                $request->url('addons', ['action' => 'option'] + $id),
                'Configurable option',
                'btn btn-default btn-sm',
            )
            . ' ' . AdminHtml::postButton(
                $request->url('addons', ['action' => $addon->isActive ? 'archive' : 'activate'] + $id),
                $addon->isActive ? 'Archive' : 'Activate',
            );
    }
}
