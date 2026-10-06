<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Hooks;

/**
 * Escaping for the checkout errors WHMCS shows customers. What customers type reaches WHMCS's
 * session and database already HTML-encoded, so entities in the text are kept rather than encoded a
 * second time (Support\Html::escape() would show "&amp;amp;"). Text the plugin stored itself is
 * plain, and takes Support\Html::escape().
 */
final class CustomerHtml
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
