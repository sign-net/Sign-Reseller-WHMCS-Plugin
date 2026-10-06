<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Addons;

use SignNet\ResellerApi\Model\ItemCode;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Support\Html;

/**
 * The forms that create and edit an add-on.
 */
final class AddonFormView
{
    /**
     * @param list<string> $currencies WHMCS's currency codes; the add-on's price is in one of them.
     */
    public static function create(AdminRequest $request, AddonFormValues $values, array $currencies): string
    {
        $fields = AdminHtml::field(
            'Code',
            AdminHtml::input('code', $values->code, ['required' => true, 'maxlength' => '50']),
            'Stored in upper case. Codes are permanent: once used, a code can never be used again, even after '
                . 'the add-on is archived.',
        )
            . self::nameAndDescription($values)
            . AdminHtml::field(
                'Currency',
                AdminHtml::select('currency', array_combine($currencies, $currencies), $values->currency),
            )
            . AdminHtml::field(
                'Billing cycle',
                AdminHtml::select('billing_cycle', CatalogueLabels::CYCLES, $values->billingCycle),
            )
            . self::priceField($values)
            . self::grantsTable($values, false);

        return '<h2>New add-on</h2>' . AdminHtml::form(
            $request->url('addons', ['action' => 'create']),
            $fields . self::buttons($request, 'Create add-on'),
        );
    }

    public static function edit(
        AdminRequest $request,
        string $addonId,
        AddonFormValues $values,
        bool $grantsLocked,
    ): string {
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
            . self::priceField($values)
            . self::grantsTable($values, $grantsLocked)
            . AdminHtml::checkbox('is_active', $values->isActive, 'Active: portals can be given this add-on');

        return '<h2>' . Html::escape('Edit add-on ' . $values->code) . '</h2>' . AdminHtml::form(
            $request->url('addons', ['action' => 'edit', 'id' => $addonId]),
            $fields . self::buttons($request, 'Save changes'),
        );
    }

    private static function nameAndDescription(AddonFormValues $values): string
    {
        return AdminHtml::field('Name', AdminHtml::input('name', $values->name, ['required' => true]))
            . AdminHtml::field('Description', AdminHtml::textarea('description', $values->description));
    }

    private static function priceField(AddonFormValues $values): string
    {
        return AdminHtml::field(
            'Price per unit',
            AdminHtml::input('price', $values->price, ['placeholder' => '5.00']),
            'Your price for one unit. Sign.net only records it; configurable options made from the add-on start '
                . 'with it. It stays editable while the add-on is in use.',
        );
    }

    private static function grantsTable(AddonFormValues $values, bool $locked): string
    {
        $rows = [];
        foreach (ItemCode::ALL as $itemCode) {
            $rows[] = [
                Html::escape(CatalogueLabels::item($itemCode)),
                AdminHtml::input('items[' . $itemCode . '][granted]', $values->grants[$itemCode] ?? '', [
                    'disabled' => $locked,
                ]),
            ];
        }
        $help = $locked
            ? 'This add-on has been attached to a portal, so what one unit grants can no longer change. Its name, '
                . 'description, price and status still can.'
            : 'How many of each item one unit grants. Leave an item blank to leave it out. Once the add-on is '
                . 'attached to a portal, this can no longer change.';

        return Html::table(['Item', 'Granted per unit'], $rows)
            . '<p class="help-block">' . Html::escape($help) . '</p>';
    }

    private static function buttons(AdminRequest $request, string $submit): string
    {
        return AdminHtml::submit($submit) . ' '
            . AdminHtml::link($request->url('addons'), 'Cancel', 'btn btn-default');
    }
}
