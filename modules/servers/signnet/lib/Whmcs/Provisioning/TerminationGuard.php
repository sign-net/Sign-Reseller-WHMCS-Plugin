<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

use WHMCS\Database\Capsule;

/**
 * Whether a termination may delete the portal. Deleting cannot be undone and its hostname can
 * never be used again, so automation (the cron's overdue and cancellation runs) follows the
 * product's setting, while an administrator's own Terminate always goes ahead.
 */
final class TerminationGuard
{
    public function __construct(private readonly bool $isAdministratorAction)
    {
    }

    public static function forThisRequest(): self
    {
        return new self(defined('ADMINAREA') && PHP_SAPI !== 'cli');
    }

    /**
     * Why the portal may not be deleted now, or null when it may.
     */
    public function refusal(int $serviceId, ProductSettings $product): ?string
    {
        if ($this->isAdministratorAction) {
            return null;
        }

        return match ($product->termination) {
            ProductSettings::TERMINATE_ALWAYS => null,
            ProductSettings::TERMINATE_NEVER => 'Not deleted: this product never deletes a Sign.net portal '
                . 'automatically. An administrator can terminate it from the service page.',
            default => self::hasCancellationRequest($serviceId) ? null : 'Not deleted: this product deletes a '
                . 'Sign.net portal automatically only after the client asks to cancel, and this service has no '
                . 'cancellation request. An administrator can terminate it from the service page.',
        };
    }

    private static function hasCancellationRequest(int $serviceId): bool
    {
        return Capsule::table('tblcancelrequests')->where('relid', $serviceId)->exists();
    }
}
