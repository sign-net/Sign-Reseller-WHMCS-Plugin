<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

/**
 * What a new WHMCS product selling a Sign.net package should be, as the administrator chose it.
 */
final class ProductSpec
{
    /**
     * @param int|null $serverGroupId Null to let WHMCS pick the Sign.net server itself.
     * @param string $overAllowance A ProductSettings::OVER_ALLOWANCE_* value.
     * @param string $termination A ProductSettings::TERMINATE_* value.
     * @param int $welcomeEmailId The product welcome email template WHMCS sends once the portal exists.
     */
    public function __construct(
        public readonly int $groupId,
        public readonly string $name,
        public readonly ?int $serverGroupId,
        public readonly string $overAllowance,
        public readonly string $termination,
        public readonly int $welcomeEmailId,
    ) {
    }
}
