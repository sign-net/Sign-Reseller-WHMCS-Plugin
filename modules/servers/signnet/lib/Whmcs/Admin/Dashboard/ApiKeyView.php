<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

use SignNet\ResellerApi\Auth\Scope;
use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Support\Html;

/**
 * Which Sign.net environment the server's key belongs to, and whether it carries every scope the
 * plugin uses. The key itself is never shown.
 */
final class ApiKeyView
{
    private const SCOPES = [
        Scope::READ => 'Read your catalogue, allowance and portals',
        Scope::PROVISION => 'Create, suspend and delete portals',
        Scope::PACKAGES => 'Manage packages and add-ons, and assign them to portals',
    ];

    /**
     * @param 'live'|'test'|string $environment
     * @param list<string>|null $grantedScopes Null when they could not be read.
     */
    public static function render(string $environment, ?array $grantedScopes): string
    {
        $html = '<p>' . Html::escape('Environment: ' . ($environment === 'live' ? 'Live' : 'Test')) . '</p>';
        if ($grantedScopes === null) {
            return $html;
        }
        $rows = [];
        foreach (self::SCOPES as $scope => $purpose) {
            $rows[] = [
                '<code>' . Html::escape($scope) . '</code>',
                Html::escape($purpose),
                in_array($scope, $grantedScopes, true)
                    ? '<span class="label label-success">Granted</span>'
                    : '<span class="label label-danger">Missing</span>',
            ];
        }
        $missing = array_diff(array_keys(self::SCOPES), $grantedScopes);

        return $html . Html::table(['Scope', 'Needed to', ''], $rows, 'table table-striped')
            . ($missing === [] ? '' : AdminHtml::alert('warning', sprintf(
                'The key lacks %s. Create a key with all three scopes in your Sign.net console and put it in '
                . 'the server\'s Password field.',
                implode(', ', $missing),
            )));
    }
}
