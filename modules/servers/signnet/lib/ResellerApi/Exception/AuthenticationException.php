<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * HTTP 401: the API key was refused (invalid_client) or the access token was (unauthorized: expired,
 * invalid or revoked). Also thrown without a network call while minting is paused after an
 * invalid_client answer.
 */
final class AuthenticationException extends ApiException
{
}
