<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

use Illuminate\Database\Query\Builder;
use SignNet\Whmcs\Catalogue\KnownColumns;
use WHMCS\Database\Capsule;

/**
 * The product welcome email WHMCS sends once a portal exists. Its signnet_* merge fields are filled
 * by the EmailPreSend hook (Hooks\WelcomeEmailFields).
 */
final class EmailTemplateInstaller
{
    public const NAME = 'Sign.net Portal Welcome';

    private const TYPE = 'product';
    private const SUBJECT = 'Your Sign.net portal is ready';
    private const BODY = <<<'HTML'
        <p>Dear {$client_name},</p>
        <p>Your Sign.net portal <strong>{$signnet_portal_name}</strong> has been set up at
        <a href="{$signnet_portal_url}">{$signnet_portal_url}</a>.</p>
        {if $signnet_domain_ready eq "no"}
        <p>We are still connecting {$signnet_portal_host} to Sign.net's hosting. The portal opens once that
        is done and the DNS records below are in place.</p>
        {/if}
        {if $signnet_dns_records}
        <p>If you have not done so yet, create these DNS records for {$signnet_portal_host} with your DNS
        provider (type, name, value):</p>
        <pre>{$signnet_dns_records}</pre>
        {/if}
        <p>Sign.net has emailed {$signnet_owner_email}, the portal's owner, a link to set a password. The link
        expires after 24 hours; if it has expired, it can be resent from this service.</p>
        <p>{$signature}</p>
        HTML;

    /**
     * Adds the template unless it exists, and answers its id.
     */
    public static function install(): int
    {
        $existing = self::findId();
        if ($existing !== null) {
            return $existing;
        }
        $now = date('Y-m-d H:i:s');

        return KnownColumns::insertGetId(
            'tblemailtemplates',
            [
                'type' => self::TYPE,
                'name' => self::NAME,
                'subject' => self::SUBJECT,
                'message' => self::BODY,
                'custom' => 1,
                'disabled' => 0,
                'plaintext' => 0,
            ],
            [
                'attachments' => '',
                'fromname' => '',
                'fromemail' => '',
                'language' => '',
                'copyto' => '',
                'blind_copy_to' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * The template's id in WHMCS's default language, or null when it is not installed.
     */
    public static function findId(): ?int
    {
        $id = Capsule::table('tblemailtemplates')
            ->where('type', self::TYPE)
            ->where('name', self::NAME)
            ->where(static function (Builder $query): void {
                // Translations are rows of the same name with a language set.
                $query->where('language', '')->orWhereNull('language');
            })
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
