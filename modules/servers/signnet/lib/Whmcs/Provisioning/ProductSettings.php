<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Provisioning;

/**
 * The product's Module Settings, in the order ConfigOptions() declares them. New settings are
 * only ever appended, because WHMCS stores them by position.
 */
final class ProductSettings
{
    public const OVER_ALLOWANCE_HOLD = 'hold';
    public const OVER_ALLOWANCE_AUTO = 'auto';

    public const TERMINATE_ON_CANCELLATION_REQUEST = 'cancel_requests';
    public const TERMINATE_ALWAYS = 'always';
    public const TERMINATE_NEVER = 'never';

    /**
     * @param string|null $packageId The Sign.net package the product sells; null when none is set.
     * @param string $overAllowance What happens to an order that would take the reseller past its allowance.
     * @param string $termination When automation (the cron) may delete the portal.
     */
    public function __construct(
        public readonly ?string $packageId,
        public readonly string $overAllowance = self::OVER_ALLOWANCE_HOLD,
        public readonly string $termination = self::TERMINATE_ON_CANCELLATION_REQUEST,
    ) {
    }

    /** The choice each dropdown label stands for, for a WHMCS that stores labels rather than keys. */
    private const LABELS = [
        'confirm automatically' => self::OVER_ALLOWANCE_AUTO,
        'always' => self::TERMINATE_ALWAYS,
        'never' => self::TERMINATE_NEVER,
    ];

    /**
     * @param array<string, mixed> $params WHMCS module parameters.
     */
    public static function fromParams(array $params): self
    {
        $packageId = trim((string) ($params['configoption1'] ?? ''));
        $overAllowance = self::choice($params['configoption2'] ?? '');
        $termination = self::choice($params['configoption3'] ?? '');

        return new self(
            $packageId === '' ? null : $packageId,
            $overAllowance === self::OVER_ALLOWANCE_AUTO ? self::OVER_ALLOWANCE_AUTO : self::OVER_ALLOWANCE_HOLD,
            in_array($termination, [self::TERMINATE_ALWAYS, self::TERMINATE_NEVER], true)
                ? $termination
                : self::TERMINATE_ON_CANCELLATION_REQUEST,
        );
    }

    private static function choice(mixed $stored): string
    {
        $value = strtolower(trim((string) $stored));

        return self::LABELS[$value] ?? $value;
    }

    /**
     * The settings in the positions fromParams() reads them from, as a product stores them.
     *
     * @return array{configoption1: string, configoption2: string, configoption3: string}
     */
    public function toConfigOptions(): array
    {
        return [
            'configoption1' => $this->packageId ?? '',
            'configoption2' => $this->overAllowance,
            'configoption3' => $this->termination,
        ];
    }

    public function confirmsOverAllowance(): bool
    {
        return $this->overAllowance === self::OVER_ALLOWANCE_AUTO;
    }
}
