<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Module;

use SignNet\ResellerApi\Auth\Scope;
use SignNet\ResellerApi\Exception\ApiException;
use SignNet\ResellerApi\Model\PrivateLabel;
use SignNet\ResellerApi\ResellerClient;
use SignNet\Whmcs\ClientFactory;
use SignNet\Whmcs\Db\Rows;
use SignNet\Whmcs\Db\Schema;
use SignNet\Whmcs\Db\ServiceLink;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Db\WhmcsServices;
use SignNet\Whmcs\Provisioning\InvalidOrderException;
use SignNet\Whmcs\Provisioning\LifecycleService;
use SignNet\Whmcs\Provisioning\PlanException;
use SignNet\Whmcs\Provisioning\ProductSettings;
use SignNet\Whmcs\Provisioning\ProvisionService;
use SignNet\Whmcs\Provisioning\TerminationGuard;
use SignNet\Whmcs\Support\ErrorText;
use SignNet\Whmcs\Support\Html;
use SignNet\Whmcs\Support\Settings;
use SignNet\Whmcs\Version;
use WHMCS\Database\Capsule;

/**
 * What the signnet_* functions WHMCS calls do. Every entry point answers WHMCS in its own terms
 * ("success" or a message) and never lets an exception reach it.
 */
final class ServerModule
{
    private const REQUIRED_SCOPES = [Scope::READ, Scope::PROVISION, Scope::PACKAGES];

    /**
     * @return array<string, mixed>
     */
    public static function metaData(): array
    {
        return [
            'DisplayName' => 'Sign.net Private Label',
            'APIVersion' => '1.1',
            'RequiresServer' => true,
            'DefaultSSLPort' => '443',
            'DefaultNonSSLPort' => '80',
        ];
    }

    /**
     * The product's Module Settings. WHMCS stores them by position: only ever append.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configOptions(): array
    {
        return [
            'Sign.net package' => [
                'Type' => 'text',
                'Size' => '40',
                'Loader' => 'signnet_PackageLoader',
                'SimpleMode' => true,
                'Description' => 'The Sign.net package every service of this product holds.',
            ],
            'Orders over the allowance' => [
                'Type' => 'dropdown',
                'Options' => [
                    ProductSettings::OVER_ALLOWANCE_HOLD => 'Hold for approval',
                    ProductSettings::OVER_ALLOWANCE_AUTO => 'Confirm automatically',
                ],
                'Default' => ProductSettings::OVER_ALLOWANCE_HOLD,
                'SimpleMode' => true,
                'Description' => 'What happens to an order that would take you past your Sign.net allowance.',
            ],
            'Automated termination' => [
                'Type' => 'dropdown',
                'Options' => [
                    ProductSettings::TERMINATE_ON_CANCELLATION_REQUEST => 'Only after a cancellation request',
                    ProductSettings::TERMINATE_ALWAYS => 'Always',
                    ProductSettings::TERMINATE_NEVER => 'Never',
                ],
                'Default' => ProductSettings::TERMINATE_ON_CANCELLATION_REQUEST,
                'SimpleMode' => true,
                'Description' => 'When the cron may delete a portal. Deleting cannot be undone and the hostname '
                    . 'can never be used again; an administrator can always terminate from the service page.',
            ],
        ];
    }

    /**
     * The package choices for the first Module Setting.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, string> Package id => label.
     *
     * @throws \RuntimeException WHMCS shows the message instead of the choices.
     */
    public static function packageOptions(array $params): array
    {
        try {
            Schema::ensure();
            $options = [];
            foreach (self::catalogueClient($params)->listPackages() as $package) {
                if ($package->isActive) {
                    $options[$package->packageId] = sprintf('%s (%s)', $package->name, $package->code);
                }
            }
        } catch (ApiException | \InvalidArgumentException $exception) {
            throw new \RuntimeException(ErrorText::describe($exception), 0, $exception);
        }
        if ($options === []) {
            throw new \RuntimeException('Create a package on the Sign.net Reseller addon\'s Packages page first.');
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{success: bool, error: string}
     */
    public static function testConnection(array $params): array
    {
        try {
            Schema::ensure();
            $client = ClientFactory::fromParams($params);
            $missing = array_values(array_diff(self::REQUIRED_SCOPES, $client->grantedScopes()));
            if ($missing !== []) {
                return ['success' => false, 'error' => 'The API key lacks ' . implode(', ', $missing)
                    . '. Create a key with reseller:read, reseller:provision and reseller:packages.'];
            }
            $quota = $client->getQuota();
        } catch (\Throwable $exception) {
            return ['success' => false, 'error' => ErrorText::describe($exception)];
        }
        if (!$quota->hasPlan()) {
            return ['success' => false, 'error' => 'Connected, but Sign.net has not put your reseller account on '
                . 'a plan yet, so no package can be assigned. Contact Sign.net.'];
        }

        return ['success' => true, 'error' => ''];
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function createAccount(array $params): string
    {
        return self::run(
            $params,
            'CreateAccount',
            static fn (): string => self::provisioning($params)->create($params),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function changePackage(array $params): string
    {
        return self::run(
            $params,
            'ChangePackage',
            static fn (): string => self::provisioning($params)->applyPlan($params),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function suspendAccount(array $params): string
    {
        return self::run($params, 'SuspendAccount', static fn (): string => self::lifecycle($params)->suspend($params));
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function unsuspendAccount(array $params): string
    {
        return self::run(
            $params,
            'UnsuspendAccount',
            static fn (): string => self::lifecycle($params)->unsuspend($params),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function terminateAccount(array $params): string
    {
        return self::run(
            $params,
            'TerminateAccount',
            static fn (): string => self::lifecycle($params)->terminate($params, TerminationGuard::forThisRequest()),
        );
    }

    /**
     * @return array<string, string> Button label => the signnet_ function it runs.
     */
    public static function adminCustomButtons(): array
    {
        return [
            'Refresh from Sign.net' => 'refresh',
            'Apply plan' => 'applyPlan',
            'Approve over-allowance & retry' => 'approveOverAllowance',
            'Retry domain attach' => 'retryDomainAttach',
            'Resend owner invite' => 'resendOwnerInvite',
            'Link existing portal' => 'linkPortal',
            'Unlink (keeps the portal)' => 'unlinkPortal',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function customAction(array $params, string $action): string
    {
        return self::run($params, $action, static function () use ($params, $action): string {
            $actions = new CustomActions(ClientFactory::fromParams($params), new ServiceRepository());

            return match ($action) {
                'applyPlan' => self::provisioning($params)->applyPlan($params),
                'approveOverAllowance' => self::approveOverAllowance($params),
                'refresh' => $actions->refresh($params),
                'retryDomainAttach' => $actions->retryDomainAttach($params),
                'resendOwnerInvite' => $actions->resendOwnerInvite($params),
                'linkPortal' => $actions->linkPortal($params),
                'unlinkPortal' => $actions->unlinkPortal($params),
                'checkDomain' => $actions->checkDomain($params),
                default => throw new \InvalidArgumentException('Unknown Sign.net action ' . $action . '.'),
            };
        });
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    public static function adminTabFields(array $params): array
    {
        try {
            Schema::ensure();
            $serviceId = (int) $params['serviceid'];
            $link = (new ServiceRepository())->find($serviceId);
            [$label, $error] = self::portal($params, $link);

            return AdminTab::fields($link, $label, $error, WhmcsServices::status($serviceId));
        } catch (\Throwable $exception) {
            return ['Sign.net portal' => 'Unavailable: ' . Html::escape(ErrorText::describe($exception))];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function clientArea(array $params): array
    {
        $serviceId = (int) $params['serviceid'];
        try {
            Schema::ensure();
            $link = (new ServiceRepository())->find($serviceId);
            [$label, $error] = self::portal($params, $link);
            $variables = ClientAreaView::variables(
                $serviceId,
                $link,
                $label,
                $error !== null,
                WhmcsServices::status($serviceId),
            );
        } catch (\Throwable) {
            $variables = ClientAreaView::variables($serviceId, null, null, true, null);
        }

        return [
            'tabOverviewModuleOutputTemplate' => 'templates/overview.tpl',
            'templateVariables' => ['signnet' => $variables + ['csrfToken' => generate_token('plain')]],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function clientAreaAllowedFunctions(): array
    {
        return ['Check DNS again' => 'checkDomain'];
    }

    /**
     * Lets the held service go past the allowance once, then runs it again: Create for a service
     * still waiting to be set up (so WHMCS activates it and sends its welcome email), otherwise
     * the plan.
     *
     * @param array<string, mixed> $params
     */
    private static function approveOverAllowance(array $params): string
    {
        $serviceId = (int) $params['serviceid'];
        $provisioning = self::provisioning($params);
        $provisioning->approveOverAllowance($serviceId);
        if (WhmcsServices::status($serviceId) !== 'Pending') {
            return $provisioning->applyPlan($params);
        }
        $result = localAPI('ModuleCreate', ['serviceid' => $serviceId]);

        return ($result['result'] ?? '') === 'success'
            ? ProvisionService::SUCCESS
            : (string) ($result['message'] ?? 'WHMCS could not run Create for this service.');
    }

    /**
     * @param array<string, mixed> $params
     * @param \Closure(): string $work
     */
    private static function run(array $params, string $action, \Closure $work): string
    {
        try {
            Schema::ensure();
            $result = $work();
        } catch (PlanException | InvalidOrderException | PortalGoneException $exception) {
            $result = $exception->getMessage();
        } catch (ApiException $exception) {
            // The transport already put the failed call in the module log.
            $result = ErrorText::describe($exception);
        } catch (\Throwable $exception) {
            $result = ErrorText::describe($exception);
            logModuleCall(
                Version::MODULE,
                $action,
                'service #' . (int) ($params['serviceid'] ?? 0),
                $exception::class . ': ' . $exception->getMessage(),
            );
        }
        if ($result === ProvisionService::SUCCESS) {
            self::forgetPortal((int) ($params['serviceid'] ?? 0));
        }

        return $result;
    }

    /**
     * The service's portal as Sign.net reports it; nothing to read for a service without one, or
     * whose portal was deleted.
     *
     * @param array<string, mixed> $params
     *
     * @return array{PrivateLabel|null, string|null} The portal, or why it could not be read.
     */
    private static function portal(array $params, ?ServiceLink $link): array
    {
        if ($link === null || !$link->isLinked() || $link->state === ServiceLink::STATE_TERMINATED) {
            return [null, null];
        }
        try {
            return [PortalCache::get(ClientFactory::fromParams($params), $link), null];
        } catch (PortalGoneException $exception) {
            return [null, $exception->getMessage()];
        } catch (ApiException | \InvalidArgumentException $exception) {
            return [null, ErrorText::describe($exception)];
        }
    }

    private static function forgetPortal(int $serviceId): void
    {
        $link = (new ServiceRepository())->find($serviceId);
        if ($link !== null && $link->tenantId !== null) {
            PortalCache::forget($link->tenantId);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function provisioning(array $params): ProvisionService
    {
        return new ProvisionService(ClientFactory::fromParams($params), new ServiceRepository(), Settings::load());
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function lifecycle(array $params): LifecycleService
    {
        return new LifecycleService(ClientFactory::fromParams($params), new ServiceRepository());
    }

    /**
     * The product page's loader runs with the product's server when it has one, and otherwise with
     * the addon's default Sign.net server.
     *
     * @param array<string, mixed> $params
     */
    private static function catalogueClient(array $params): ResellerClient
    {
        if (trim((string) ($params['serverhostname'] ?? '')) !== '') {
            return ClientFactory::fromParams($params);
        }
        $serverId = Settings::load()->defaultServerId() ?? (int) (Rows::first(
            Capsule::table('tblservers')->where('type', Version::MODULE)->where('disabled', 0)->orderBy('id'),
        )['id'] ?? 0);
        if ($serverId === 0) {
            throw new \InvalidArgumentException(
                'Add a Sign.net server first (System Settings > Servers), with the API host as Hostname and the '
                . 'snk_ key as Password.',
            );
        }

        return ClientFactory::fromServerId($serverId);
    }
}
