<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Admin\AdminRequest;
use SignNet\Whmcs\Admin\Page;
use SignNet\Whmcs\Admin\SignNetServer;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Settings;

/**
 * The reseller's plan and allowance, its API key, and what needs attention. Each part reports its
 * own failure, so what WHMCS knows still shows when Sign.net cannot be reached.
 */
final class DashboardPage implements Page
{
    public function __construct(
        private readonly SignNetServer $server,
        private readonly Settings $settings,
    ) {
    }

    public function render(AdminRequest $request): string
    {
        return '<h2>Plan and allowance</h2>' . $this->allowance()
            . '<h2>API key</h2>' . $this->apiKey()
            . '<h2>Needs attention</h2>' . $this->attention();
    }

    private function allowance(): string
    {
        try {
            $quota = $this->server->client()->getQuota();
        } catch (\Exception $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error));
        }

        return QuotaView::render($quota, $this->settings->lowAllowancePercent());
    }

    private function apiKey(): string
    {
        try {
            $environment = $this->server->keyEnvironment();
        } catch (\InvalidArgumentException $error) {
            return AdminHtml::alert('danger', ErrorText::describe($error));
        }
        try {
            $scopes = $this->server->client()->grantedScopes();
        } catch (\Exception $error) {
            return ApiKeyView::render($environment, null) . AdminHtml::alert('danger', ErrorText::describe($error));
        }

        return ApiKeyView::render($environment, $scopes);
    }

    private function attention(): string
    {
        $failure = '';
        try {
            $labels = $this->server->client()->listPrivateLabels();
        } catch (\Exception $error) {
            $labels = null;
            $failure = AdminHtml::alert(
                'danger',
                'Portals were not compared with Sign.net: ' . ErrorText::describe($error),
            );
        }
        $items = (new AttentionList())->build($labels, $this->settings->invalidSettings());

        return $failure . AttentionView::render($items);
    }
}
