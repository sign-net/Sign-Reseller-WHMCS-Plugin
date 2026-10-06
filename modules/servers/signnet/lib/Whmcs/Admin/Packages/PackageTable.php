<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Packages;

use SignNet\ResellerApi\Model\PackageSummary;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Admin\CatalogueLimit;
use SignNet\Whmcs\Catalogue\PriceMapper;
use SignNet\Whmcs\Support\Html;

/**
 * The reseller's packages, with how much of Sign.net's cap they use.
 */
final class PackageTable
{
    /**
     * @param list<PackageSummary> $packages
     */
    public static function render(AdminRequest $request, array $packages): string
    {
        $archived = count(array_filter($packages, static fn (PackageSummary $package): bool => !$package->isActive));
        $html = '<p>' . Html::escape(CatalogueLimit::describe(count($packages), $archived, 'packages')) . '</p>'
            . '<p>' . (CatalogueLimit::isFull(count($packages))
                ? Html::escape('Your package catalogue is full.')
                : AdminHtml::link($request->url('packages', ['action' => 'create']), 'New package', 'btn btn-primary'))
            . '</p>';
        if ($packages === []) {
            return $html . '<p>' . Html::escape('You have no packages yet.') . '</p>';
        }
        $rows = array_map(static fn (PackageSummary $package): array => self::row($request, $package), $packages);

        return $html . Html::table(['Code', 'Name', 'Cycle', 'Base price', 'Status', ''], $rows, 'table table-striped');
    }

    /**
     * @return list<string>
     */
    private static function row(AdminRequest $request, PackageSummary $package): array
    {
        return [
            '<code>' . Html::escape($package->code) . '</code>',
            Html::escape($package->name),
            Html::escape(CatalogueLabels::cycle($package->billingCycle)),
            Html::escape(PriceMapper::toDecimal($package->basePriceMinor) . ' ' . $package->currency),
            $package->isActive
                ? '<span class="label label-success">Active</span>'
                : '<span class="label label-default">Archived</span>',
            self::actions($request, $package),
        ];
    }

    private static function actions(AdminRequest $request, PackageSummary $package): string
    {
        $id = ['id' => $package->packageId];

        return AdminHtml::link($request->url('packages', ['action' => 'edit'] + $id), 'Edit', 'btn btn-default btn-sm')
            . ' ' . AdminHtml::link(
                $request->url('packages', ['action' => 'product'] + $id),
                'WHMCS product',
                'btn btn-default btn-sm',
            )
            . ' ' . AdminHtml::postButton(
                $request->url('packages', ['action' => $package->isActive ? 'archive' : 'activate'] + $id),
                $package->isActive ? 'Archive' : 'Activate',
            );
    }
}
