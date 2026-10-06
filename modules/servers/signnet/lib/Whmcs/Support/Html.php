<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Support;

/**
 * Output helpers for the HTML the plugin writes into WHMCS's admin pages.
 */
final class Html
{
    public static function escape(string|int|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A table of already-escaped cells.
     *
     * @param list<string> $headings Plain text.
     * @param list<list<string>> $rows HTML.
     */
    public static function table(array $headings, array $rows, string $class = 'table table-condensed'): string
    {
        $head = implode('', array_map(
            static fn (string $heading): string => '<th>' . self::escape($heading) . '</th>',
            $headings,
        ));
        $body = implode('', array_map(
            static fn (array $cells): string => '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>',
            $rows,
        ));

        return '<table class="' . self::escape($class) . '"><thead><tr>' . $head . '</tr></thead><tbody>' . $body
            . '</tbody></table>';
    }
}
