<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Packages;

use SignNet\ResellerApi\Model\PackageUpdate;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\Page;
use SignNet\Whmcs\Admin\SignNetServer;
use SignNet\Whmcs\Catalogue\Currencies;
use SignNet\Whmcs\Support\ErrorText;

/**
 * The reseller's Sign.net packages: list, create, edit, archive and activate them, and sell them
 * as WHMCS products.
 */
final class PackagesPage implements Page
{
    public function __construct(private readonly SignNetServer $server)
    {
    }

    public function render(AdminRequest $request): string
    {
        $packageId = $request->query('id');

        return match ($request->action()) {
            'create' => $request->isPost() ? $this->create($request) : $this->createForm($request),
            'edit' => $request->isPost() ? $this->update($request, $packageId) : $this->editForm($request, $packageId),
            'archive', 'activate' => $request->isPost()
                ? $this->setActive($request, $packageId, $request->action() === 'activate')
                : $this->listing($request),
            'product', 'link' => (new ProductActions($this->server))->render($request, $packageId),
            default => $this->listing($request),
        };
    }

    private function listing(AdminRequest $request, string $notice = ''): string
    {
        try {
            $packages = $this->server->client()->listPackages();
        } catch (\Exception $error) {
            return $notice . AdminHtml::alert('danger', ErrorText::describe($error));
        }

        return $notice . PackageTable::render($request, $packages);
    }

    private function createForm(AdminRequest $request, string $failure = ''): string
    {
        $currencies = Currencies::codes();
        $values = $request->isPost()
            ? PackageFormValues::fromRequest($request)
            : PackageFormValues::blank($currencies[0] ?? '');

        return $failure . PackageFormView::create($request, $values, $currencies);
    }

    private function create(AdminRequest $request): string
    {
        $values = PackageFormValues::fromRequest($request);
        try {
            $this->server->client()->createPackage($values->toInput());
        } catch (\Exception $error) {
            return $this->createForm($request, AdminHtml::alert('danger', ErrorText::describe($error)));
        }

        return $this->listing($request, AdminHtml::alert(
            'success',
            sprintf('Package %s was created in Sign.net.', strtoupper($values->code)),
        ));
    }

    private function editForm(AdminRequest $request, string $packageId): string
    {
        try {
            $package = $this->server->client()->getPackage($packageId);
        } catch (\Exception $error) {
            return $this->listing($request, AdminHtml::alert('danger', ErrorText::describe($error)));
        }

        return PackageFormView::edit($request, $packageId, PackageFormValues::fromPackage($package));
    }

    private function update(AdminRequest $request, string $packageId): string
    {
        $values = PackageFormValues::fromRequest($request);
        try {
            $client = $this->server->client();
            $update = $values->toUpdate($client->getPackage($packageId));
            if ($update->toArray() === []) {
                return $this->listing($request, AdminHtml::alert('info', 'Nothing was changed.'));
            }
            $client->updatePackage($packageId, $update);
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error))
                . PackageFormView::edit($request, $packageId, $values);
        }

        return $this->listing($request, AdminHtml::alert('success', sprintf('Package %s was saved.', $values->code)));
    }

    private function setActive(AdminRequest $request, string $packageId, bool $isActive): string
    {
        try {
            $this->server->client()->updatePackage($packageId, (new PackageUpdate())->withActive($isActive));
        } catch (\Exception $error) {
            return $this->listing($request, AdminHtml::alert('danger', ErrorText::describe($error)));
        }

        return $this->listing($request, AdminHtml::alert('success', $isActive
            ? 'The package is active again.'
            : 'The package is archived. It still counts towards your 100, and its code stays taken.'));
    }
}
