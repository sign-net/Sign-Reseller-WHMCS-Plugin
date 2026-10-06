<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\ResellerApi\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Model\ProvisionRequest;

final class ProvisionRequestTest extends TestCase
{
    #[Test]
    public function itKeepsOnlyTheBrandingThatWasGiven(): void
    {
        $request = self::withBranding([
            'featureStamps' => true,
            'logoAlt' => 'Acme',
            'favicon' => null,
            'colorFooterBackground' => '#000000',
        ]);

        self::assertSame(
            ['logoAlt' => 'Acme', 'colorFooterBackground' => '#000000', 'featureStamps' => true],
            $request->branding,
        );
        self::assertSame(
            ['domain' => 'sign.acme.test', 'appName' => 'Acme'] + $request->branding,
            $request->toArray()['config'],
        );
    }

    #[Test]
    public function itAcceptsEveryDocumentedBrandingKey(): void
    {
        $branding = [
            'logoAlt' => 'a',
            'favicon' => 'a',
            'copyrightHolder' => 'a',
            'supportUrl' => 'a',
            'websiteUrl' => 'a',
            'socialLinks' => [],
            'colorPrimary' => 'a',
            'colorPrimaryForeground' => 'a',
            'colorAccent' => 'a',
            'colorAccentForeground' => 'a',
            'colorAppBarBackground' => 'a',
            'colorFooterBackground' => 'a',
            'featureBusinessRegistration' => true,
            'featureBusinessProfile' => true,
            'featureDomainSelector' => true,
            'featurePointsSystem' => false,
            'featureSignUpPage' => true,
            'featureReferralCodes' => false,
            'featureStamps' => true,
            'featureHelpVideos' => true,
            'featureSocialLinks' => true,
        ];

        self::assertSame($branding, self::withBranding($branding)->branding);
    }

    #[Test]
    public function itLeavesThePackageOutWhenNoneIsGiven(): void
    {
        $request = new ProvisionRequest('a@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme');

        self::assertArrayNotHasKey('package', $request->toArray());
    }

    #[Test]
    public function itSendsThePackageToAssign(): void
    {
        $request = new ProvisionRequest('a@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme', [], 'pkg-1');

        self::assertSame(['packageId' => 'pkg-1'], $request->toArray()['package']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBranding(): iterable
    {
        yield 'unknown key' => [['primaryColor' => '#fff']];
        yield 'snake case key' => [['color_primary' => '#fff']];
        yield 'number for a string' => [['colorPrimary' => 255]];
        yield 'string for a boolean' => [['featureStamps' => 'yes']];
        yield 'integer for a boolean' => [['featureStamps' => 1]];
        yield 'social links not a list' => [['socialLinks' => ['name' => 'X', 'url' => 'u', 'icon' => 'i']]];
        yield 'social link missing icon' => [['socialLinks' => [['name' => 'X', 'url' => 'u']]]];
        yield 'social link extra field' => [
            ['socialLinks' => [['name' => 'X', 'url' => 'u', 'icon' => 'i', 'order' => 1]]],
        ];
        yield 'social link with null' => [['socialLinks' => [['name' => 'X', 'url' => null, 'icon' => 'i']]]];
    }

    /**
     * @param array<string, mixed> $branding
     */
    #[Test]
    #[DataProvider('invalidBranding')]
    public function itRefusesBrandingItCannotSend(array $branding): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::withBranding($branding);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string|null}>
     */
    public static function invalidFields(): iterable
    {
        yield 'blank email' => [' ', 'Ann', 'Lee', 'sign.acme.test', 'Acme', null];
        yield 'blank first name' => ['a@acme.test', '', 'Lee', 'sign.acme.test', 'Acme', null];
        yield 'blank last name' => ['a@acme.test', 'Ann', ' ', 'sign.acme.test', 'Acme', null];
        yield 'blank domain' => ['a@acme.test', 'Ann', 'Lee', '', 'Acme', null];
        yield 'blank app name' => ['a@acme.test', 'Ann', 'Lee', 'sign.acme.test', "\t", null];
        yield 'blank package id' => ['a@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme', ''];
    }

    #[Test]
    #[DataProvider('invalidFields')]
    public function itRefusesARequestTheBackendWouldReject(
        string $email,
        string $firstName,
        string $lastName,
        string $domain,
        string $appName,
        ?string $packageId,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new ProvisionRequest($email, $firstName, $lastName, $domain, $appName, [], $packageId);
    }

    /**
     * @param array<string, mixed> $branding
     */
    private static function withBranding(array $branding): ProvisionRequest
    {
        return new ProvisionRequest('a@acme.test', 'Ann', 'Lee', 'sign.acme.test', 'Acme', $branding);
    }
}
