<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Support;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Exception\AuthenticationException;
use SignNet\ResellerApi\Exception\ConflictException;
use SignNet\ResellerApi\Exception\EndpointNotAvailableException;
use SignNet\ResellerApi\Exception\ErrorCode;
use SignNet\ResellerApi\Exception\ForbiddenException;
use SignNet\ResellerApi\Exception\NotFoundException;
use SignNet\ResellerApi\Exception\RateLimitedException;
use SignNet\ResellerApi\Exception\ServerException;
use SignNet\ResellerApi\Exception\TransportException;
use SignNet\ResellerApi\Exception\ValidationException;

/**
 * What to tell a WHMCS administrator when a call to Sign.net fails. The API client's messages
 * never contain the key or a token, so they are safe to show.
 */
final class ErrorText
{
    public static function describe(\Throwable $error): string
    {
        $text = match (true) {
            $error instanceof AuthenticationException => self::authentication($error),
            $error instanceof ForbiddenException => self::forbidden($error),
            $error instanceof RateLimitedException => self::rateLimited($error),
            $error instanceof ValidationException => self::validation($error),
            $error instanceof NotFoundException => self::notFound($error),
            $error instanceof ConflictException => self::conflict($error),
            $error instanceof EndpointNotAvailableException => 'This Sign.net server does not offer that '
                . 'endpoint yet; it may be older than this plugin.',
            $error instanceof TransportException => 'Could not reach Sign.net: ' . $error->getMessage(),
            $error instanceof ServerException => sprintf(
                'Sign.net could not complete the request (HTTP %s). Try again later.',
                $error->httpStatus ?? 'error',
            ),
            default => $error->getMessage(),
        };
        $requestId = $error instanceof ApiException ? $error->requestId : null;

        return $requestId === null ? $text : $text . ' (request ' . $requestId . ')';
    }

    private static function authentication(AuthenticationException $error): string
    {
        // No HTTP status: minting is paused after an earlier refusal, which the message explains.
        // The pause belongs to that key at that address, so correcting either lifts it.
        if ($error->httpStatus === null) {
            return $error->getMessage() . ' A corrected key or hostname is tried straight away.';
        }

        return 'Sign.net refused the API key. Check that the server\'s Password field holds a current '
            . 'snk_ key.';
    }

    private static function forbidden(ForbiddenException $error): string
    {
        return match ($error->errorCode) {
            ErrorCode::INSUFFICIENT_SCOPE => 'The Sign.net API key lacks a scope this needs. Use a key with '
                . 'reseller:read, reseller:provision and reseller:packages.',
            ErrorCode::FORBIDDEN => 'Sign.net refused: the portal is not one of yours, or it was deleted.',
            ErrorCode::SESSION_REQUIRED => 'Sign.net accepts this only from someone signed in to its reseller '
                . 'console, not from an API key.',
            default => 'Sign.net refused this (' . self::code($error) . ').',
        };
    }

    private static function rateLimited(RateLimitedException $error): string
    {
        if ($error->httpStatus === null) {
            return $error->getMessage();
        }
        if ($error->errorCode === ErrorCode::TOO_FREQUENT) {
            return 'Sign.net sends a person at most one set-password email every five minutes, counting the one '
                . 'sent when the portal was created. Try again later.';
        }

        return $error->retryAfterSeconds === null
            ? 'Sign.net is rate limiting these requests; try again shortly.'
            : sprintf('Sign.net is rate limiting these requests; try again in %d seconds.', $error->retryAfterSeconds);
    }

    private static function validation(ValidationException $error): string
    {
        return match ($error->errorCode) {
            ErrorCode::INVALID_REQUEST => 'Sign.net refused the request as invalid.',
            ErrorCode::OWNER_INVALID_EMAIL => 'Sign.net refused the client\'s email address for the portal owner. '
                . 'Correct it on the client\'s profile, then run Create again.',
            ErrorCode::OWNER_INVALID_NAME => 'Sign.net refused the client\'s name for the portal owner: it needs a '
                . 'first and a last name. Correct the client\'s profile, then run Create again.',
            ErrorCode::DOMAIN_INVALID => 'Sign.net refused the portal address. Use a hostname such as '
                . 'sign.example.com in the service\'s Portal address field.',
            ErrorCode::HOST_RESERVED => 'Sign.net keeps that portal address for itself. Ask the customer for '
                . 'another.',
            ErrorCode::INVALID_COLOR => 'Sign.net refused a portal colour. Check the colours in the addon\'s '
                . 'settings.',
            ErrorCode::INVALID_URL => 'Sign.net refused a portal web address. Check the portal support and '
                . 'website URLs in the addon\'s settings.',
            ErrorCode::INVALID_TARGET => 'Sign.net refused: that is your reseller account itself, not one of '
                . 'its portals.',
            ErrorCode::EMPTY_CODE => 'Enter a code.',
            ErrorCode::EMPTY_NAME => 'Enter a name.',
            ErrorCode::DUPLICATE_ITEM => 'Each item can be listed only once.',
            ErrorCode::NO_GRANTS => 'An add-on must grant at least one item.',
            ErrorCode::INVALID_QUANTITY => 'A quantity must be a whole number of at least 1.',
            ErrorCode::CONFIRMATION_INVALID, ErrorCode::CONFIRMATION_EXPIRED => 'Sign.net did not accept the '
                . 'confirmation to go past the allowance, or it expired. Try again.',
            default => 'Sign.net refused the request (' . self::code($error) . ').',
        };
    }

    private static function notFound(NotFoundException $error): string
    {
        return match ($error->errorCode) {
            ErrorCode::PACKAGE_NOT_FOUND => 'The Sign.net package set on this product no longer exists.',
            ErrorCode::ADDON_NOT_FOUND => 'An add-on this product sells no longer exists in Sign.net.',
            ErrorCode::USER_NOT_FOUND => 'That portal user no longer exists.',
            ErrorCode::NOT_ASSIGNED => 'The portal holds no package.',
            default => 'Sign.net could not find it (' . self::code($error) . ').',
        };
    }

    private static function conflict(ConflictException $error): string
    {
        return match ($error->errorCode) {
            ErrorCode::HOST_TAKEN => 'That portal address is already in use on Sign.net.',
            ErrorCode::SUSPENDED_BY_PLATFORM => 'Sign.net suspended this portal, and only Sign.net can lift it.',
            ErrorCode::PACKAGE_INACTIVE => 'The Sign.net package set on this product is archived.',
            ErrorCode::ADDON_INACTIVE => 'An add-on this product sells is archived in Sign.net.',
            ErrorCode::CANNOT_REMOVE_OWNER => 'The portal owner cannot be removed.',
            ErrorCode::SEAT_QUOTA_REACHED => 'The portal has taken every seat its package allows. Add seats to '
                . 'its package first.',
            ErrorCode::CODE_IN_USE => 'That code is taken by one of your packages or add-ons, archived ones '
                . 'included; a code can never be reused.',
            ErrorCode::CATALOGUE_FULL => 'You have reached Sign.net\'s limit of 100 packages or 100 add-ons; '
                . 'archived ones count too.',
            ErrorCode::ADDON_IN_USE => 'This add-on has been attached to a portal, so what it grants can no '
                . 'longer change. Its price still can.',
            ErrorCode::NO_SUBSCRIPTION => 'Sign.net has not put your reseller account on a plan, so no package '
                . 'can be assigned. Contact Sign.net.',
            default => 'Sign.net refused this (' . self::code($error) . ').',
        };
    }

    private static function code(ApiException $error): string
    {
        return $error->errorCode ?? 'HTTP ' . ($error->httpStatus ?? '?');
    }
}
