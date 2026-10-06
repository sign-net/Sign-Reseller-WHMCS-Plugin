<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

use SignNet\Whmcs\Admin\AdminHtml;
use SignNet\Whmcs\Support\Html;

final class AttentionView
{
    /**
     * @param list<AttentionItem> $items
     */
    public static function render(array $items): string
    {
        if ($items === []) {
            return AdminHtml::alert('success', 'Nothing needs your attention.');
        }

        return Html::table(
            ['Service', 'Portal', 'What needs attention'],
            array_map(self::row(...), $items),
            'table table-striped',
        );
    }

    /**
     * @return list<string>
     */
    private static function row(AttentionItem $item): array
    {
        $details = array_map(
            static fn (string $detail): string => '<li>' . Html::escape($detail) . '</li>',
            $item->details,
        );

        return [
            self::service($item),
            Html::escape($item->hostname),
            '<strong>' . Html::escape($item->problem) . '</strong>'
                . ($details === [] ? '' : '<ul>' . implode('', $details) . '</ul>'),
        ];
    }

    private static function service(AttentionItem $item): string
    {
        $url = $item->serviceUrl();
        if ($url !== null) {
            return AdminHtml::link($url, '#' . $item->serviceId);
        }

        return $item->serviceId === null ? '' : Html::escape(sprintf('#%d (deleted)', $item->serviceId));
    }
}
