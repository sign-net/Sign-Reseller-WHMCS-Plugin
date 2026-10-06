<?php

declare(strict_types=1);

namespace SignNet\ResellerApi\Auth;

/**
 * The scopes a reseller API key can carry.
 */
final class Scope
{
    public const READ = 'reseller:read';
    public const PROVISION = 'reseller:provision';
    public const PACKAGES = 'reseller:packages';

    private function __construct()
    {
    }
}
