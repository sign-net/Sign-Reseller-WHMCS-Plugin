<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Model\AddonSummary;
use SignNet\ResellerApi\ResellerClient;

/**
 * The reseller's Sign.net add-ons by code, listed on first use and kept for the run, so the
 * allowance check and the attachments share one read of Sign.net's rate-limited billing endpoints.
 */
final class AddonCatalogue
{
    /**
     * @var array<string, AddonSummary>|null
     */
    private ?array $byCode = null;

    public function __construct(private readonly ResellerClient $client)
    {
    }

    /**
     * @throws PlanException When Sign.net has no such add-on, or it is archived and $quantity asks for some.
     * @throws ApiException
     */
    public function require(string $code, int $quantity): AddonSummary
    {
        $addon = $this->byCode()['code:' . strtoupper($code)] ?? null;
        if ($addon === null) {
            throw new PlanException(sprintf(
                'The product sells add-on %1$s, which does not exist in Sign.net. Check its "addon_%1$s" '
                . 'configurable option.',
                $code,
            ));
        }
        if ($quantity > 0 && !$addon->isActive) {
            throw new PlanException(sprintf('Add-on %s is archived in Sign.net.', $code));
        }

        return $addon;
    }

    /**
     * @return array<string, AddonSummary>
     *
     * @throws ApiException
     */
    private function byCode(): array
    {
        if ($this->byCode === null) {
            $byCode = [];
            foreach ($this->client->listAddons() as $addon) {
                $byCode['code:' . strtoupper($addon->code)] = $addon;
            }
            $this->byCode = $byCode;
        }

        return $this->byCode;
    }
}
