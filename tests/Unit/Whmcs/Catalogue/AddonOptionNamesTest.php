<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Catalogue;

use PHPUnit\Framework\Attributes\Test;
use SignNet\Tests\Support\DatabaseTestCase;
use SignNet\Tests\Support\WhmcsCatalogueSchema;
use SignNet\Whmcs\Catalogue\AddonOptionNames;
use SignNet\Whmcs\Db\Rows;
use WHMCS\Database\Capsule;

final class AddonOptionNamesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WhmcsCatalogueSchema::create();
    }

    #[Test]
    public function itEncodesTheNamesAnEarlierVersionStoredPlain(): void
    {
        $optionId = self::insertOption(
            'Sign.net add-on: <b>Seats</b> & "more"',
            'Sells Sign.net add-on SEATS5 by the unit.',
            'addon_SEATS5|<b>Seats</b> & "more"',
            ['<b>Seats</b> & "more"'],
        );

        AddonOptionNames::encodeStored();

        $encoded = '&lt;b&gt;Seats&lt;/b&gt; &amp; &quot;more&quot;';
        self::assertSame([
            'group' => 'Sign.net add-on: ' . $encoded,
            'description' => 'Sells Sign.net add-on SEATS5 by the unit.',
            'option' => 'addon_SEATS5|' . $encoded,
            'prices' => [$encoded],
        ], self::stored($optionId));
    }

    #[Test]
    public function itLeavesEncodedAndPlainNamesAsTheyAre(): void
    {
        $encodedId = self::insertOption(
            'Extras &amp; more',
            'Typed in WHMCS: &quot;Extras&quot;',
            'addon_DOCS|Docs &amp; seats &lt;10&gt;',
            ['Docs &amp; seats &lt;10&gt;'],
        );
        $plainId = self::insertOption(
            'Sign.net add-on: 5 seats',
            'Sells Sign.net add-on SEATS5 by the unit.',
            'addon_SEATS5|5 seats',
            ['5 seats'],
        );
        $before = [self::stored($encodedId), self::stored($plainId)];

        AddonOptionNames::encodeStored();

        self::assertSame($before, [self::stored($encodedId), self::stored($plainId)]);
    }

    #[Test]
    public function itChangesNothingWhenItRunsAgain(): void
    {
        $optionId = self::insertOption('Sign.net add-on: A & B', '', 'addon_AB|A & B', ['A & B', "It's 'A'"]);
        AddonOptionNames::encodeStored();
        $once = self::stored($optionId);

        AddonOptionNames::encodeStored();

        self::assertSame($once, self::stored($optionId));
        self::assertSame(['A &amp; B', 'It&#039;s &#039;A&#039;'], $once['prices']);
    }

    #[Test]
    public function itLeavesTheCodeAndOptionsOfOtherModulesAlone(): void
    {
        $addonId = self::insertOption('Extras', '', 'addon_A&B|<b>A</b>', []);
        $similarId = self::insertOption('Disk <b>', '<b>', 'addonsDisk|<b>Disk</b>', ['<b>Disk</b>']);
        $otherId = self::insertOption('Hosting <b>', '<b>', 'Disk space|<b>Disk</b>', ['<b>Disk</b>']);
        $before = [self::stored($similarId), self::stored($otherId)];

        AddonOptionNames::encodeStored();

        self::assertSame('addon_A&B|&lt;b&gt;A&lt;/b&gt;', self::stored($addonId)['option']);
        self::assertSame($before, [self::stored($similarId), self::stored($otherId)]);
    }

    /**
     * An option in a group of its own, with a price row per name in $priceNames.
     *
     * @param list<string> $priceNames
     */
    private static function insertOption(
        string $groupName,
        string $groupDescription,
        string $optionName,
        array $priceNames,
    ): int {
        $groupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'name' => $groupName,
            'description' => $groupDescription,
        ]);
        $optionId = Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $groupId,
            'optionname' => $optionName,
            'optiontype' => '4',
            'qtyminimum' => 0,
            'qtymaximum' => 10,
            'order' => 0,
            'hidden' => 0,
        ]);
        foreach ($priceNames as $order => $name) {
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $optionId,
                'optionname' => $name,
                'sortorder' => $order,
                'hidden' => 0,
            ]);
        }

        return $optionId;
    }

    /**
     * @return array{group: string, description: string, option: string, prices: list<string>}
     */
    private static function stored(int $optionId): array
    {
        $option = Rows::first(Capsule::table('tblproductconfigoptions')->where('id', $optionId)) ?? [];
        $group = Rows::first(Capsule::table('tblproductconfiggroups')->where('id', $option['gid'] ?? 0)) ?? [];
        $prices = Capsule::table('tblproductconfigoptionssub')->where('configid', $optionId)->orderBy('sortorder');

        return [
            'group' => (string) ($group['name'] ?? ''),
            'description' => (string) ($group['description'] ?? ''),
            'option' => (string) ($option['optionname'] ?? ''),
            'prices' => array_map(static fn (array $row): string => (string) $row['optionname'], Rows::all($prices)),
        ];
    }
}
