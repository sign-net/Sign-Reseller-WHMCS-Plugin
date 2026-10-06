<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Packages;

use SignNet\ResellerApi\Model\ItemCode;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Support\Html;

/**
 * The forms that create and edit a package.
 */
final class PackageFormView
{
    /**
     * @param list<string> $currencies WHMCS's currency codes; the package's prices are in one of them.
     */
    public static function create(AdminRequest $request, PackageFormValues $values, array $currencies): string
    {
        $currencyOptions = array_combine($currencies, $currencies);
        $fields = AdminHtml::field(
            'Code',
            AdminHtml::input('code', $values->code, ['required' => true, 'maxlength' => '50']),
            'Stored in upper case. Codes are permanent: once used, a code can never be used again, even after '
                . 'the package is archived.',
        )
            . self::nameAndDescription($values)
            . AdminHtml::field('Currency', AdminHtml::select('currency', $currencyOptions, $values->currency))
            . AdminHtml::field(
                'Billing cycle',
                AdminHtml::select('billing_cycle', CatalogueLabels::CYCLES, $values->billingCycle),
            )
            . self::priceAndItems($values);

        return '<h2>New package</h2>' . AdminHtml::form(
            $request->url('packages', ['action' => 'create']),
            $fields . self::buttons($request, 'Create package'),
        );
    }

    public static function edit(AdminRequest $request, string $packageId, PackageFormValues $values): string
    {
        $fixed = sprintf(
            'Code %s, priced in %s, billed %s. These cannot change.',
            $values->code,
            $values->currency,
            strtolower(CatalogueLabels::cycle($values->billingCycle)),
        );
        $fields = '<p>' . Html::escape($fixed) . '</p>'
            . AdminHtml::hidden('code', $values->code)
            . AdminHtml::hidden('currency', $values->currency)
            . AdminHtml::hidden('billing_cycle', $values->billingCycle)
            . self::nameAndDescription($values)
            . self::priceAndItems($values)
            . AdminHtml::checkbox('is_active', $values->isActive, 'Active: portals can be given this package');

        return '<h2>' . Html::escape('Edit package ' . $values->code) . '</h2>' . AdminHtml::form(
            $request->url('packages', ['action' => 'edit', 'id' => $packageId]),
            $fields . self::buttons($request, 'Save changes'),
        );
    }

    private static function nameAndDescription(PackageFormValues $values): string
    {
        return AdminHtml::field('Name', AdminHtml::input('name', $values->name, ['required' => true]))
            . AdminHtml::field('Description', AdminHtml::textarea('description', $values->description));
    }

    private static function priceAndItems(PackageFormValues $values): string
    {
        return AdminHtml::field(
            'Base price',
            AdminHtml::input('base_price', $values->basePrice, ['placeholder' => '19.00']),
            'Your price for the package. Sign.net only records it; WHMCS products made from the package start '
                . 'with it.',
        )
            . self::itemsTable($values)
            . '<p class="help-block">Leave Included blank to leave an item out. A portal given no notarisations '
            . 'notarises from your own pool instead, and you are billed what it uses. Quantities can be changed '
            . 'later, but a change only reaches portals given the package afterwards; portals that already hold it '
            . 'keep what they were given.</p>';
    }

    private static function itemsTable(PackageFormValues $values): string
    {
        $rows = [];
        foreach (ItemCode::ALL as $itemCode) {
            $fields = $values->items[$itemCode] ?? ['included' => '', 'bundleSize' => '', 'bundlePrice' => ''];
            $rows[] = [
                Html::escape(CatalogueLabels::item($itemCode)),
                AdminHtml::input('items[' . $itemCode . '][included]', $fields['included']),
                AdminHtml::input('items[' . $itemCode . '][bundle_size]', $fields['bundleSize']),
                AdminHtml::input('items[' . $itemCode . '][bundle_price]', $fields['bundlePrice']),
            ];
        }

        return Html::table(['Item', 'Included', 'Overage bundle size', 'Overage bundle price'], $rows);
    }

    private static function buttons(AdminRequest $request, string $submit): string
    {
        return AdminHtml::submit($submit) . ' '
            . AdminHtml::link($request->url('packages'), 'Cancel', 'btn btn-default');
    }
}
