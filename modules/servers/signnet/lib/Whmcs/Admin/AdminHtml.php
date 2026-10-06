<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use SignNet\Whmcs\Support\Html;

/**
 * Building blocks for the addon's pages, in the Bootstrap 3 markup of WHMCS's admin theme.
 *
 * Parameters named $text are escaped here; parameters named $html must already be safe.
 */
final class AdminHtml
{
    public static function alert(string $level, string $text): string
    {
        return self::alertHtml($level, Html::escape($text));
    }

    public static function alertHtml(string $level, string $html): string
    {
        return '<div class="alert alert-' . Html::escape($level) . '">' . $html . '</div>';
    }

    public static function link(string $url, string $text, string $class = ''): string
    {
        return '<a href="' . Html::escape($url) . '"' . self::attributes(['class' => $class]) . '>'
            . Html::escape($text) . '</a>';
    }

    /**
     * A POST form carrying WHMCS's CSRF token.
     *
     * @param array<string, string|bool> $attributes
     */
    public static function form(string $action, string $html, array $attributes = []): string
    {
        return '<form' . self::attributes(['method' => 'post', 'action' => $action] + $attributes) . '>'
            . generate_token('form') . $html . '</form>';
    }

    /**
     * A one-button POST form, for actions such as archiving.
     */
    public static function postButton(string $action, string $text): string
    {
        return self::form($action, self::submit($text, 'btn btn-default btn-sm'), ['style' => 'display:inline-block']);
    }

    public static function submit(string $text, string $class = 'btn btn-primary'): string
    {
        return '<button type="submit" class="' . Html::escape($class) . '">' . Html::escape($text) . '</button>';
    }

    /**
     * A labelled form row.
     */
    public static function field(string $label, string $controlHtml, string $help = ''): string
    {
        return '<div class="form-group"><label>' . Html::escape($label) . '</label>' . $controlHtml
            . ($help === '' ? '' : '<p class="help-block">' . Html::escape($help) . '</p>') . '</div>';
    }

    /**
     * @param array<string, string|bool> $attributes Replace the defaults, type="text" and class="form-control".
     */
    public static function input(string $name, string $value, array $attributes = []): string
    {
        $attributes = array_merge(['type' => 'text', 'class' => 'form-control'], $attributes);

        return '<input' . self::attributes($attributes + ['name' => $name, 'value' => $value]) . '>';
    }

    public static function hidden(string $name, string $value): string
    {
        return self::input($name, $value, ['type' => 'hidden', 'class' => '']);
    }

    public static function textarea(string $name, string $value): string
    {
        return '<textarea' . self::attributes(['name' => $name, 'class' => 'form-control', 'rows' => '3']) . '>'
            . Html::escape($value) . '</textarea>';
    }

    /**
     * @param array<array-key, string> $options Value => label.
     */
    public static function select(string $name, array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . Html::escape($value) . '"'
                . self::attributes(['selected' => (string) $value === $selected]) . '>'
                . Html::escape($label) . '</option>';
        }

        return '<select' . self::attributes(['name' => $name, 'class' => 'form-control']) . '>' . $html . '</select>';
    }

    public static function checkbox(string $name, bool $checked, string $label, string $value = '1'): string
    {
        return '<div class="checkbox"><label><input' . self::attributes([
            'type' => 'checkbox',
            'name' => $name,
            'value' => $value,
            'checked' => $checked,
        ]) . '> ' . Html::escape($label) . '</label></div>';
    }

    /**
     * @param array<string, string|bool> $attributes true writes the bare attribute; false or '' leaves it out.
     */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value === true) {
                $html .= ' ' . $name;
            } elseif ($value !== false && $value !== '') {
                $html .= ' ' . $name . '="' . Html::escape($value) . '"';
            }
        }

        return $html;
    }
}
