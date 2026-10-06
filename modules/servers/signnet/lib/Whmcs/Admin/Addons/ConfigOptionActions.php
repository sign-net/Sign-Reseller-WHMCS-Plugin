<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Addons;

use SignNet\ResellerApi\Model\Addon;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\FormNumbers;
use SignNet\Whmcs\Admin\SignNetServer;
use SignNet\Whmcs\Catalogue\ConfigOptionFactory;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Html;

/**
 * Selling an add-on in WHMCS as a Quantity configurable option on the chosen products.
 */
final class ConfigOptionActions
{
    private readonly ConfigOptionFactory $options;

    public function __construct(private readonly SignNetServer $server)
    {
        $this->options = new ConfigOptionFactory();
    }

    public function render(AdminRequest $request, string $addonId): string
    {
        try {
            $addon = $this->server->client()->getAddon($addonId);
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error))
                . AdminHtml::link($request->url('addons'), 'Back to add-ons', 'btn btn-default');
        }
        $outcome = $request->isPost() ? $this->save($request, $addon) : '';

        return $outcome . ConfigOptionForm::render($request, $addon, $this->options->find($addon->code));
    }

    private function save(AdminRequest $request, Addon $addon): string
    {
        try {
            $option = $this->options->save(
                $addon,
                FormNumbers::wholeNumber('Maximum quantity', $request->post('max_quantity')),
                $request->postIds('products'),
            );
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error));
        }
        $text = sprintf(
            'The configurable option is saved in group "%s" and offered on %d product(s). ',
            $option->groupName,
            count($option->linkedProductIds),
        );

        return AdminHtml::alertHtml('success', Html::escape($text) . AdminHtml::link(
            'configproductoptions.php?action=managegroup&id=' . $option->groupId,
            'Open the option group',
        ));
    }
}
