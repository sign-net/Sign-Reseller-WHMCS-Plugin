<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin\Dashboard;

/**
 * One thing on the dashboard's attention list.
 */
final class AttentionItem
{
    /**
     * @param list<string> $details
     * @param int|null $serviceId The WHMCS service it concerns, if any.
     * @param int|null $clientId The service's client; null when the service no longer exists.
     */
    public function __construct(
        public readonly string $problem,
        public readonly array $details = [],
        public readonly ?int $serviceId = null,
        public readonly ?int $clientId = null,
        public readonly string $hostname = '',
    ) {
    }

    /**
     * The service's page in WHMCS's admin area, while the service exists.
     */
    public function serviceUrl(): ?string
    {
        if ($this->serviceId === null || $this->clientId === null) {
            return null;
        }

        return sprintf('clientsservices.php?userid=%d&id=%d', $this->clientId, $this->serviceId);
    }
}
