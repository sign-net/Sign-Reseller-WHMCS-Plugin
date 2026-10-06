<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Packages;

use SignNet\ResellerApi\Model\Package;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\EmailTemplateInstaller;
use SignNet\Whmcs\Admin\SignNetServer;
use SignNet\Whmcs\Catalogue\ProductFactory;
use SignNet\Whmcs\Catalogue\ProductSpec;
use SignNet\Whmcs\Provisioning\ProductSettings;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Html;

/**
 * Selling a package in WHMCS: creating a product for it, or pointing an existing product at it.
 */
final class ProductActions
{
    public function __construct(private readonly SignNetServer $server)
    {
    }

    public function render(AdminRequest $request, string $packageId): string
    {
        try {
            $package = $this->server->client()->getPackage($packageId);
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error))
                . AdminHtml::link($request->url('packages'), 'Back to packages', 'btn btn-default');
        }
        $outcome = match (true) {
            !$request->isPost() => '',
            $request->action() === 'link' => $this->link($request, $package),
            default => $this->create($request, $package),
        };

        return $outcome . ProductForms::render($request, $package);
    }

    private function create(AdminRequest $request, Package $package): string
    {
        try {
            $productId = (new ProductFactory())->create($package, self::spec($request, $package));
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error));
        }

        return self::success(
            sprintf('WHMCS product #%d was created to sell package %s.', $productId, $package->code),
            $productId,
        );
    }

    private function link(AdminRequest $request, Package $package): string
    {
        $productId = (int) $request->post('product_id');
        try {
            (new ProductFactory())->link($productId, $package->packageId);
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error));
        }

        return self::success(
            sprintf('WHMCS product #%d now sells package %s through Sign.net.', $productId, $package->code),
            $productId,
        );
    }

    /**
     * @throws \InvalidArgumentException When no product group was chosen.
     */
    private static function spec(AdminRequest $request, Package $package): ProductSpec
    {
        $groupId = (int) $request->post('group_id');
        if ($groupId <= 0) {
            throw new \InvalidArgumentException('Choose the product group the new product goes in.');
        }
        $serverGroupId = (int) $request->post('server_group_id');
        $policies = ProductSettings::fromParams([
            'configoption2' => $request->post('over_allowance'),
            'configoption3' => $request->post('termination'),
        ]);

        return new ProductSpec(
            $groupId,
            $request->post('name') === '' ? $package->name : $request->post('name'),
            $serverGroupId > 0 ? $serverGroupId : null,
            $policies->overAllowance,
            $policies->termination,
            EmailTemplateInstaller::install(),
        );
    }

    private static function success(string $text, int $productId): string
    {
        return AdminHtml::alertHtml('success', Html::escape($text) . ' ' . AdminHtml::link(
            'configproducts.php?action=edit&id=' . $productId,
            'Open the product',
        ));
    }
}
