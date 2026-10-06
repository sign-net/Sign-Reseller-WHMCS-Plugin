<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Hooks;

use SignNet\Whmcs\Admin\EmailTemplateInstaller;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Provisioning\DomainState;
use SignNet\Whmcs\Support\Html;

/**
 * The signnet_* merge fields of the "Sign.net Portal Welcome" email (EmailPreSend): the portal's
 * address, name and owner, and the DNS records to create.
 */
final class WelcomeEmailFields
{
    /**
     * @param array<string, mixed> $vars EmailPreSend's parameters: messagename and relid (the service).
     *
     * @return array<string, string> Escaped for the HTML template; empty for any other email, or for a
     *     service without a portal. Escaped in full, unlike checkout text: the link row holds plain
     *     text, so a name typed as "R&amp;D" reaches the email as typed.
     */
    public function mergeFields(array $vars): array
    {
        if (($vars['messagename'] ?? null) !== EmailTemplateInstaller::NAME) {
            return [];
        }
        $relatedId = $vars['relid'] ?? null;
        $link = is_numeric($relatedId) ? (new ServiceRepository())->find((int) $relatedId) : null;
        if ($link === null || !$link->isLinked()) {
            return [];
        }
        $records = array_map(
            static fn (array $record): string => $record['type'] . ' ' . $record['name'] . ' ' . $record['value'],
            DomainState::records($link->domainSetup),
        );

        return array_map(Html::escape(...), [
            'signnet_portal_url' => 'https://' . $link->hostname,
            'signnet_portal_name' => $link->portalName,
            'signnet_portal_host' => $link->hostname,
            'signnet_owner_email' => $link->ownerEmail,
            'signnet_dns_records' => implode("\n", $records),
            'signnet_domain_ready' => ($link->domainSetup['attached'] ?? null) === true ? 'yes' : 'no',
        ]);
    }
}
