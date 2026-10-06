<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Admin\Addons\AddonsPage;
use SignNet\Whmcs\Admin\Dashboard\DashboardPage;
use SignNet\Whmcs\Admin\Packages\PackagesPage;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Settings;
use SignNet\Whmcs\Version;

/**
 * The addon's admin area (Addons > Sign.net Reseller): tabs, the CSRF check, and the page asked for.
 * A failure is shown on the page rather than reaching WHMCS.
 */
final class AdminRouter
{
    public const NO_SERVER = 'Add a Sign.net server first (System Settings > Servers), with the API host as '
        . 'Hostname and the snk_ key as Password.';

    private const TABS = [
        'dashboard' => 'Dashboard',
        'packages' => 'Packages',
        'addons' => 'Add-ons',
    ];

    /**
     * @param array<string, mixed> $vars What WHMCS passes the addon's output function; modulelink is used.
     * @param array<mixed> $query The request's query string ($_GET).
     * @param array<mixed> $post The submitted form ($_POST).
     */
    public function render(array $vars, array $query, array $post, string $method): string
    {
        $moduleLink = $vars['modulelink'] ?? null;
        $request = new AdminRequest(
            is_string($moduleLink) ? $moduleLink : 'addonmodules.php?module=' . Version::ADDON,
            $method,
            $query,
            $post,
        );
        $page = isset(self::TABS[$request->page()]) ? $request->page() : 'dashboard';
        $tabs = $this->tabs($request, $page);
        try {
            return $tabs . $this->content($request, $page);
        } catch (\Throwable $error) {
            return $tabs . AdminHtml::alert('danger', ErrorText::describe($error));
        }
    }

    private function content(AdminRequest $request, string $page): string
    {
        if ($request->isPost()) {
            check_token('WHMCS.admin.default');
        }
        Schema::ensure();
        $settings = Settings::load();
        $serverId = SignNetServers::forPages($settings);
        if ($serverId === null) {
            return AdminHtml::alert('warning', self::NO_SERVER);
        }

        return $this->page($page, new SignNetServer($serverId), $settings)->render($request);
    }

    private function page(string $page, SignNetServer $server, Settings $settings): Page
    {
        return match ($page) {
            'packages' => new PackagesPage($server),
            'addons' => new AddonsPage($server),
            default => new DashboardPage($server, $settings),
        };
    }

    private function tabs(AdminRequest $request, string $current): string
    {
        $tabs = '';
        foreach (self::TABS as $page => $label) {
            $tabs .= '<li' . ($page === $current ? ' class="active"' : '') . '>'
                . AdminHtml::link($request->url($page), $label) . '</li>';
        }

        return '<ul class="nav nav-tabs" style="margin-bottom:20px">' . $tabs . '</ul>';
    }
}
