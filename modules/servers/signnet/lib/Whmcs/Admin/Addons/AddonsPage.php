<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Addons;

use SignNet\ResellerApi\Model\AddonUpdate;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\Page;
use SignNet\Whmcs\Admin\SignNetServer;
use SignNet\Whmcs\Catalogue\Currencies;
use SignNet\Whmcs\Support\ErrorText;

/**
 * The reseller's Sign.net add-ons: list, create, edit, archive and activate them, and sell them as
 * WHMCS configurable options.
 */
final class AddonsPage implements Page
{
    public function __construct(private readonly SignNetServer $server)
    {
    }

    public function render(AdminRequest $request): string
    {
        $addonId = $request->query('id');

        return match ($request->action()) {
            'create' => $request->isPost() ? $this->create($request) : $this->createForm($request),
            'edit' => $request->isPost() ? $this->update($request, $addonId) : $this->editForm($request, $addonId),
            'archive', 'activate' => $request->isPost()
                ? $this->setActive($request, $addonId, $request->action() === 'activate')
                : $this->listing($request),
            'option' => (new ConfigOptionActions($this->server))->render($request, $addonId),
            default => $this->listing($request),
        };
    }

    private function listing(AdminRequest $request, string $notice = ''): string
    {
        try {
            $addons = $this->server->client()->listAddons();
        } catch (\Exception $error) {
            return $notice . AdminHtml::alert('danger', ErrorText::describe($error));
        }

        return $notice . AddonTable::render($request, $addons);
    }

    private function createForm(AdminRequest $request, string $failure = ''): string
    {
        $currencies = Currencies::codes();
        $values = $request->isPost()
            ? AddonFormValues::fromRequest($request)
            : AddonFormValues::blank($currencies[0] ?? '');

        return $failure . AddonFormView::create($request, $values, $currencies);
    }

    private function create(AdminRequest $request): string
    {
        $values = AddonFormValues::fromRequest($request);
        try {
            $this->server->client()->createAddon($values->toInput());
        } catch (\Exception $error) {
            return $this->createForm($request, AdminHtml::alert('danger', ErrorText::describe($error)));
        }

        return $this->listing($request, AdminHtml::alert(
            'success',
            sprintf('Add-on %s was created in Sign.net.', strtoupper($values->code)),
        ));
    }

    private function editForm(AdminRequest $request, string $addonId): string
    {
        try {
            $addon = $this->server->client()->getAddon($addonId);
        } catch (\Exception $error) {
            return $this->listing($request, AdminHtml::alert('danger', ErrorText::describe($error)));
        }

        return AddonFormView::edit($request, $addonId, AddonFormValues::fromAddon($addon), $addon->grantsLocked);
    }

    private function update(AdminRequest $request, string $addonId): string
    {
        $values = AddonFormValues::fromRequest($request);
        $grantsLocked = false;
        try {
            $client = $this->server->client();
            $current = $client->getAddon($addonId);
            $grantsLocked = $current->grantsLocked;
            $update = $values->toUpdate($current);
            if ($update->toArray() === []) {
                return $this->listing($request, AdminHtml::alert('info', 'Nothing was changed.'));
            }
            $client->updateAddon($addonId, $update);
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error))
                . AddonFormView::edit($request, $addonId, $values, $grantsLocked);
        }

        return $this->listing($request, AdminHtml::alert('success', sprintf('Add-on %s was saved.', $values->code)));
    }

    private function setActive(AdminRequest $request, string $addonId, bool $isActive): string
    {
        try {
            $this->server->client()->updateAddon($addonId, (new AddonUpdate())->withActive($isActive));
        } catch (\Exception $error) {
            return $this->listing($request, AdminHtml::alert('danger', ErrorText::describe($error)));
        }

        return $this->listing($request, AdminHtml::alert('success', $isActive
            ? 'The add-on is active again.'
            : 'The add-on is archived. It still counts towards your 100, and its code stays taken.'));
    }
}
