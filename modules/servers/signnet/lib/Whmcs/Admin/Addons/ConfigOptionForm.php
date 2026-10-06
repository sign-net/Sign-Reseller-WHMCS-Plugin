<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Addons;

use SignNet\ResellerApi\Model\Addon;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Catalogue\AddonOption;
use SignNet\Whmcs\Catalogue\PriceMapper;
use SignNet\Whmcs\Catalogue\WhmcsCatalogue;
use SignNet\Whmcs\Provisioning\OrderDetails;
use SignNet\Whmcs\Support\Html;

/**
 * The form that creates an add-on's configurable option, or changes which products offer it.
 */
final class ConfigOptionForm
{
    private const DEFAULT_MAX_QUANTITY = '100';

    public static function render(AdminRequest $request, Addon $addon, ?AddonOption $option): string
    {
        $maxQuantity = $request->posted('max_quantity')
            ? $request->post('max_quantity')
            : (string) ($option->maxQuantity ?? self::DEFAULT_MAX_QUANTITY);
        $fields = AdminHtml::field(
            'Maximum quantity',
            AdminHtml::input('max_quantity', $maxQuantity, ['type' => 'number', 'min' => '0']),
            'The most units one order can take. Customers can always choose 0.',
        ) . self::productChoices($request, $option);

        return '<h2>' . Html::escape(sprintf('Sell %s (%s) in WHMCS', $addon->name, $addon->code)) . '</h2>'
            . '<p>' . Html::escape(self::intro($addon, $option)) . '</p>'
            . AdminHtml::form(
                $request->url('addons', ['action' => 'option', 'id' => $addon->addonId]),
                $fields . AdminHtml::submit($option === null ? 'Create configurable option' : 'Save'),
            )
            . '<p>' . AdminHtml::link($request->url('addons'), 'Back to add-ons', 'btn btn-default') . '</p>';
    }

    private static function intro(Addon $addon, ?AddonOption $option): string
    {
        if ($option !== null) {
            return sprintf(
                'This add-on is sold through option group "%s". Saving changes its maximum quantity and which '
                . 'products offer it; its prices in WHMCS are left as they are.',
                $option->groupName,
            );
        }

        return sprintf(
            'Creates the option group "Sign.net add-on: %s" with a Quantity option named "%s", priced at %s %s '
            . 'per unit, %s, and the same per month on products billed on other cycles.',
            $addon->name,
            OrderDetails::ADDON_OPTION_PREFIX . strtoupper($addon->code) . '|' . $addon->name,
            PriceMapper::toDecimal($addon->priceMinor),
            $addon->currency,
            strtolower(CatalogueLabels::cycle($addon->billingCycle)),
        );
    }

    private static function productChoices(AdminRequest $request, ?AddonOption $option): string
    {
        $products = WhmcsCatalogue::signNetProducts();
        if ($products === []) {
            return AdminHtml::alert('info', 'No WHMCS product uses the Sign.net module yet. Create one from a '
                . 'package first; the option can be linked to it afterwards.');
        }
        $chosen = $request->isPost() ? $request->postIds('products') : ($option->linkedProductIds ?? []);
        $boxes = '';
        foreach ($products as $productId => $name) {
            $boxes .= AdminHtml::checkbox(
                'products[]',
                in_array($productId, $chosen, true),
                sprintf('%s (#%d)', $name, $productId),
                (string) $productId,
            );
        }

        return AdminHtml::field('Offer it on', $boxes, 'Products using the Sign.net module.');
    }
}
