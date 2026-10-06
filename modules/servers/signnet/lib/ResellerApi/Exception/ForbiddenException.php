<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Exception;

/**
 * HTTP 403: insufficient_scope when the key lacks the scope a call needs, session_required for a
 * call only a signed-in console admin may make, and forbidden when the reseller in the path is not
 * the key's or the target tenant is outside its subtree (including one already deprovisioned).
 */
final class ForbiddenException extends ApiException
{
}
