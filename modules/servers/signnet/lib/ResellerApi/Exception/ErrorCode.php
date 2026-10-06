<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * Error codes the backend documents, as they appear in ApiException::$errorCode.
 *
 * The console answers codes in upper case inside its {"status": "Err"|"Fail"} envelope, and the
 * token endpoint in lower case as {"error": "code"}; both are lower-cased into one namespace, so
 * HOST_TAKEN becomes host_taken.
 */
final class ErrorCode
{
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_CLIENT = 'invalid_client';
    public const UNAUTHORIZED = 'unauthorized';
    public const FORBIDDEN = 'forbidden';
    public const INSUFFICIENT_SCOPE = 'insufficient_scope';
    public const SESSION_REQUIRED = 'session_required';
    public const INVALID_JSON = 'invalid_json';
    public const INVALID_TARGET = 'invalid_target';
    public const INVALID_QUANTITY = 'invalid_quantity';
    public const INVALID_PERIOD = 'invalid_period';
    public const INVALID_EMAIL = 'invalid_email';
    public const INVALID_NAME = 'invalid_name';
    public const OWNER_INVALID_EMAIL = 'owner_invalid_email';
    public const OWNER_INVALID_NAME = 'owner_invalid_name';
    public const DOMAIN_INVALID = 'domain_invalid';
    public const HOST_RESERVED = 'host_reserved';
    public const HOST_TAKEN = 'host_taken';
    public const INVALID_COLOR = 'invalid_color';
    public const INVALID_URL = 'invalid_url';
    public const TOO_MANY_SOCIAL_LINKS = 'too_many_social_links';
    public const EMPTY_CODE = 'empty_code';
    public const EMPTY_NAME = 'empty_name';
    public const DUPLICATE_ITEM = 'duplicate_item';
    public const NO_GRANTS = 'no_grants';
    public const CONFIRMATION_INVALID = 'confirmation_invalid';
    public const CONFIRMATION_EXPIRED = 'confirmation_expired';
    public const NOT_FOUND = 'not_found';
    public const USER_NOT_FOUND = 'user_not_found';
    public const PACKAGE_NOT_FOUND = 'package_not_found';
    public const ADDON_NOT_FOUND = 'addon_not_found';
    public const NOT_ASSIGNED = 'not_assigned';
    public const CODE_IN_USE = 'code_in_use';
    public const CATALOGUE_FULL = 'catalogue_full';
    public const ADDON_IN_USE = 'addon_in_use';
    public const PACKAGE_INACTIVE = 'package_inactive';
    public const ADDON_INACTIVE = 'addon_inactive';
    public const ALREADY_ASSIGNED = 'already_assigned';
    public const ALREADY_ATTACHED = 'already_attached';
    public const NO_SUBSCRIPTION = 'no_subscription';
    public const SUSPENDED_BY_PLATFORM = 'suspended_by_platform';
    public const CANNOT_REMOVE_OWNER = 'cannot_remove_owner';
    public const SEAT_QUOTA_REACHED = 'seat_quota_reached';
    public const TOO_FREQUENT = 'too_frequent';
    public const INTERNAL_SERVER_ERROR = 'internal_server_error';

    private function __construct()
    {
    }
}
