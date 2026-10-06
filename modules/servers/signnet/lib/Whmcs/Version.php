<?php

declare(strict_types=1);

namespace SignNet\Whmcs;

final class Version
{
    public const PLUGIN = '1.1.0';

    /** The provisioning module's system name, and the prefix WHMCS gives its functions. */
    public const MODULE = 'signnet';

    /** The addon module's system name. */
    public const ADDON = 'signnet_reseller';

    private function __construct()
    {
    }
}
