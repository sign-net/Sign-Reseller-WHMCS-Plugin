<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Catalogue;

/**
 * An add-on's configurable option as it stands in WHMCS.
 */
final class AddonOption
{
    /**
     * @param list<int> $linkedProductIds The products whose orders offer it.
     */
    public function __construct(
        public readonly int $optionId,
        public readonly int $groupId,
        public readonly string $groupName,
        public readonly int $maxQuantity,
        public readonly array $linkedProductIds,
    ) {
    }
}
