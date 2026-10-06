<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

use Illuminate\Database\Query\Builder;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Provisioning\OrderDetails;
use WHMCS\Database\Capsule;

/**
 * The product custom fields in which a customer orders their portal: "Portal address" and
 * "Portal name". A field also counts when it is named by its key, alone or as "key|Display name".
 */
final class PortalFields
{
    private const FIELDS = [
        OrderDetails::HOST_FIELD_KEY => [
            'fieldname' => OrderDetails::HOST_FIELD,
            'description' => 'Where your portal will live, e.g. sign.example.com',
            'required' => 'on',
            'sortorder' => 0,
        ],
        OrderDetails::NAME_FIELD_KEY => [
            'fieldname' => OrderDetails::NAME_FIELD,
            'description' => 'The name your portal shows; leave blank to use your company name',
            'required' => '',
            'sortorder' => 1,
        ],
    ];

    /**
     * Gives the product both fields, leaving any it already has as they are.
     */
    public static function ensureFor(int $productId): void
    {
        $existing = array_map(
            static fn (array $row): string => (string) $row['fieldname'],
            Rows::all(self::productFields([$productId])),
        );
        foreach (self::FIELDS as $key => $field) {
            $present = array_filter($existing, static fn (string $name): bool => self::isField($name, $key));
            if ($present === []) {
                self::insert($productId, $field);
            }
        }
    }

    /**
     * The ids of each product's portal address fields.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<int>> Product id => field ids.
     */
    public static function hostFieldIds(array $productIds): array
    {
        $fieldIds = [];
        foreach (Rows::all(self::productFields($productIds)) as $row) {
            if (self::isField((string) $row['fieldname'], OrderDetails::HOST_FIELD_KEY)) {
                $fieldIds[(int) $row['relid']][] = (int) $row['id'];
            }
        }

        return $fieldIds;
    }

    /**
     * @param list<int> $productIds
     */
    private static function productFields(array $productIds): Builder
    {
        return Capsule::table('tblcustomfields')
            ->select('id', 'relid', 'fieldname')
            ->where('type', 'product')
            ->whereIn('relid', $productIds)
            ->orderBy('id');
    }

    private static function isField(string $fieldName, string $key): bool
    {
        return $fieldName === self::FIELDS[$key]['fieldname']
            || $fieldName === $key
            || str_starts_with($fieldName, $key . '|');
    }

    /**
     * @param array{fieldname: string, description: string, required: string, sortorder: int} $field
     */
    private static function insert(int $productId, array $field): void
    {
        $now = date('Y-m-d H:i:s');
        KnownColumns::insertGetId(
            'tblcustomfields',
            ['type' => 'product', 'relid' => $productId, 'fieldtype' => 'text', 'showorder' => 'on'] + $field,
            [
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => '',
                'showinvoice' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
