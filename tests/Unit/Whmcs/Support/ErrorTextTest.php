<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit\Whmcs\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\NotFoundException;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Exception\ValidationException;
use SignNet\Whmcs\Support\ErrorText;

final class ErrorTextTest extends TestCase
{
    /**
     * @return iterable<string, array{ApiException, string}>
     */
    public static function failures(): iterable
    {
        yield 'refused key' => [
            new AuthenticationException('x', 401, 'invalid_client'),
            'Sign.net refused the API key. Check that the server\'s Password field holds a current snk_ key.',
        ];
        yield 'key paused after a refusal' => [
            new AuthenticationException('Token minting for this Sign.net API key is paused until <time>.', null),
            'Token minting for this Sign.net API key is paused until <time>. A corrected key or hostname is tried '
                . 'straight away.',
        ];
        yield 'missing scope' => [
            new ForbiddenException('x', 403, 'insufficient_scope'),
            'The Sign.net API key lacks a scope this needs. Use a key with reseller:read, reseller:provision and '
                . 'reseller:packages.',
        ];
        yield 'portal not the reseller\'s' => [
            new ForbiddenException('x', 403, 'forbidden'),
            'Sign.net refused: the portal is not one of yours, or it was deleted.',
        ];
        yield 'console session only' => [
            new ForbiddenException('x', 403, 'session_required'),
            'Sign.net accepts this only from someone signed in to its reseller console, not from an API key.',
        ];
        yield 'rate limited' => [
            new RateLimitedException('x', 30),
            'Sign.net is rate limiting these requests; try again in 30 seconds.',
        ];
        yield 'invite throttle' => [
            new RateLimitedException('x', 300, 429, 'too_frequent'),
            'Sign.net sends a person at most one set-password email every five minutes, counting the one sent '
                . 'when the portal was created. Try again later.',
        ];
        yield 'invalid request, said neutrally' => [
            new ValidationException('x', 400, 'invalid_request'),
            'Sign.net refused the request as invalid.',
        ];
        yield 'owner email' => [
            new ValidationException('x', 400, 'owner_invalid_email'),
            'Sign.net refused the client\'s email address for the portal owner. Correct it on the client\'s '
                . 'profile, then run Create again.',
        ];
        yield 'owner name' => [
            new ValidationException('x', 400, 'owner_invalid_name'),
            'Sign.net refused the client\'s name for the portal owner: it needs a first and a last name. Correct '
                . 'the client\'s profile, then run Create again.',
        ];
        yield 'portal address' => [
            new ValidationException('x', 400, 'domain_invalid'),
            'Sign.net refused the portal address. Use a hostname such as sign.example.com in the service\'s Portal '
                . 'address field.',
        ];
        yield 'reserved address' => [
            new ValidationException('x', 400, 'host_reserved'),
            'Sign.net keeps that portal address for itself. Ask the customer for another.',
        ];
        yield 'portal colour' => [
            new ValidationException('x', 400, 'invalid_color'),
            'Sign.net refused a portal colour. Check the colours in the addon\'s settings.',
        ];
        yield 'portal web address' => [
            new ValidationException('x', 400, 'invalid_url'),
            'Sign.net refused a portal web address. Check the portal support and website URLs in the addon\'s '
                . 'settings.',
        ];
        yield 'the reseller itself' => [
            new ValidationException('x', 400, 'invalid_target'),
            'Sign.net refused: that is your reseller account itself, not one of its portals.',
        ];
        yield 'taken address' => [
            new ConflictException('x', 400, 'host_taken'),
            'That portal address is already in use on Sign.net.',
        ];
        yield 'seats used up' => [
            new ConflictException('x', 400, 'seat_quota_reached'),
            'The portal has taken every seat its package allows. Add seats to its package first.',
        ];
        yield 'nothing assigned' => [
            new NotFoundException('x', 400, 'not_assigned'),
            'The portal holds no package.',
        ];
        yield 'code taken' => [
            new ConflictException('x', 400, 'code_in_use'),
            'That code is taken by one of your packages or add-ons, archived ones included; a code can never be '
                . 'reused.',
        ];
        yield 'catalogue full' => [
            new ConflictException('x', 400, 'catalogue_full'),
            'You have reached Sign.net\'s limit of 100 packages or 100 add-ons; archived ones count too.',
        ];
        yield 'grants locked' => [
            new ConflictException('x', 400, 'addon_in_use'),
            'This add-on has been attached to a portal, so what it grants can no longer change. Its price still can.',
        ];
        yield 'unknown validation code' => [
            new ValidationException('x', 400, 'something_new'),
            'Sign.net refused the request (something_new).',
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function itTellsTheAdministratorWhatWentWrong(ApiException $failure, string $message): void
    {
        self::assertSame($message, ErrorText::describe($failure));
    }

    #[Test]
    public function itNamesTheRequestAsTheModuleLogDoes(): void
    {
        $failure = new TransportException('Connection timed out', true, 'req-42');

        self::assertSame(
            'Could not reach Sign.net: Connection timed out (request req-42)',
            ErrorText::describe($failure),
        );
    }
}
