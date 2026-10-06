<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Model\DnsRecord;
use SignNet\ResellerApi\Model\DomainSetup;

/**
 * A portal's domain setup as the plugin stores and shows it (ServiceLink::$domainSetup).
 */
final class DomainState
{
    /**
     * @return array{
     *     attached: bool,
     *     verified: bool,
     *     records: list<array{type: string, name: string, value: string}>,
     *     note: string|null,
     * }
     */
    public static function toArray(DomainSetup $setup): array
    {
        return [
            'attached' => $setup->attached,
            'verified' => $setup->verified,
            'records' => array_map(
                static fn (DnsRecord $record): array => [
                    'type' => $record->type,
                    'name' => $record->name,
                    'value' => $record->value,
                ],
                $setup->records,
            ),
            'note' => $setup->note,
        ];
    }

    /**
     * The DNS records in a stored domain setup, skipping anything malformed.
     *
     * @param array<string, mixed>|null $stored
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    public static function records(?array $stored): array
    {
        $records = [];
        foreach (is_array($stored['records'] ?? null) ? $stored['records'] : [] as $record) {
            if (self::isRecord($record)) {
                $records[] = ['type' => $record['type'], 'name' => $record['name'], 'value' => $record['value']];
            }
        }

        return $records;
    }

    /**
     * @phpstan-assert-if-true array{type: string, name: string, value: string} $record
     */
    private static function isRecord(mixed $record): bool
    {
        return is_array($record)
            && is_string($record['type'] ?? null)
            && is_string($record['name'] ?? null)
            && is_string($record['value'] ?? null);
    }
}
