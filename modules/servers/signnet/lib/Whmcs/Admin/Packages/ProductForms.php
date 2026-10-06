<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Packages;

use SignNet\ResellerApi\Model\Package;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\CatalogueLabels;
use SignNet\Whmcs\Catalogue\PriceMapper;
use SignNet\Whmcs\Catalogue\WhmcsCatalogue;
use SignNet\Whmcs\Provisioning\ProductSettings;
use SignNet\Whmcs\Support\Html;

/**
 * The forms that create a WHMCS product for a package, or link an existing product to it. A failed
 * submission comes back with what was chosen.
 */
final class ProductForms
{
    private const OVER_ALLOWANCE = [
        ProductSettings::OVER_ALLOWANCE_HOLD => 'Hold them for approval',
        ProductSettings::OVER_ALLOWANCE_AUTO => 'Confirm them automatically',
    ];

    private const TERMINATION = [
        ProductSettings::TERMINATE_ON_CANCELLATION_REQUEST => 'Only after the client asks to cancel',
        ProductSettings::TERMINATE_ALWAYS => 'Always',
        ProductSettings::TERMINATE_NEVER => 'Never: an administrator terminates by hand',
    ];

    public static function render(AdminRequest $request, Package $package): string
    {
        $price = sprintf(
            '%s %s, %s',
            PriceMapper::toDecimal($package->basePriceMinor),
            $package->currency,
            strtolower(CatalogueLabels::cycle($package->billingCycle)),
        );

        return '<h2>' . Html::escape(sprintf('Sell %s (%s) in WHMCS', $package->name, $package->code)) . '</h2>'
            . self::createForm($request, $package, $price)
            . self::linkForm($request, $package)
            . '<p>' . AdminHtml::link($request->url('packages'), 'Back to packages', 'btn btn-default') . '</p>';
    }

    private static function createForm(AdminRequest $request, Package $package, string $price): string
    {
        $groups = WhmcsCatalogue::productGroups();
        if ($groups === []) {
            return '<h3>Create a product</h3>'
                . AdminHtml::alert('info', 'Create a product group first (System Settings > Products/Services).');
        }
        $fields = AdminHtml::field('Product group', AdminHtml::select('group_id', $groups, $request->post('group_id')))
            . AdminHtml::field(
                'Product name',
                AdminHtml::input('name', $request->posted('name') ? $request->post('name') : $package->name),
            )
            . AdminHtml::field(
                'Server group',
                AdminHtml::select(
                    'server_group_id',
                    ['' => 'None'] + WhmcsCatalogue::serverGroups(),
                    $request->post('server_group_id'),
                ),
                'Optional: the group of Sign.net servers WHMCS picks from.',
            )
            . self::policyFields($request);
        $intro = sprintf(
            'The product uses the Sign.net module, is priced at %s, is set up as soon as it is paid for, asks '
            . 'for the portal\'s address and name when ordered, and sends the "Sign.net Portal Welcome" email.',
            $price,
        );

        return '<h3>Create a product</h3><p>' . Html::escape($intro) . '</p>' . AdminHtml::form(
            $request->url('packages', ['action' => 'product', 'id' => $package->packageId]),
            $fields . AdminHtml::submit('Create product'),
        );
    }

    private static function policyFields(AdminRequest $request): string
    {
        return AdminHtml::field(
            'Orders that would go over your Sign.net allowance',
            AdminHtml::select('over_allowance', self::OVER_ALLOWANCE, $request->post('over_allowance')),
        )
            . AdminHtml::field(
                'Automated termination',
                AdminHtml::select('termination', self::TERMINATION, $request->post('termination')),
                'Terminating deletes the portal for good, and its address can never be used again.',
            );
    }

    private static function linkForm(AdminRequest $request, Package $package): string
    {
        $products = [];
        foreach (WhmcsCatalogue::products() as $productId => $name) {
            $products[$productId] = sprintf('%s (#%d)', $name, $productId);
        }
        if ($products === []) {
            return '';
        }
        $field = AdminHtml::field(
            'Product',
            AdminHtml::select('product_id', $products, $request->post('product_id')),
            'It switches to the Sign.net module and this package, and gains the portal address and name order '
                . 'fields if it lacks them. Its prices are left as they are.',
        );

        return '<h3>Or link an existing product</h3>' . AdminHtml::form(
            $request->url('packages', ['action' => 'link', 'id' => $package->packageId]),
            $field . AdminHtml::submit('Link product', 'btn btn-default'),
        );
    }
}
