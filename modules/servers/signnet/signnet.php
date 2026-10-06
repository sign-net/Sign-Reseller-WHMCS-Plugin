<?php

/**
 * Sign.net Private Label provisioning module: one WHMCS service is one private-label portal.
 *
 * WHMCS calls these functions by name; each hands over to SignNet\Whmcs\Module\ServerModule.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

/**
 * @return array<string, mixed>
 */
function signnet_MetaData(): array
{
    return \SignNet\Whmcs\Module\ServerModule::metaData();
}

/**
 * @return array<string, array<string, mixed>>
 */
function signnet_ConfigOptions(): array
{
    return \SignNet\Whmcs\Module\ServerModule::configOptions();
}

/**
 * @param array<string, mixed> $params
 *
 * @return array<string, string>
 */
function signnet_PackageLoader(array $params): array
{
    return \SignNet\Whmcs\Module\ServerModule::packageOptions($params);
}

/**
 * @param array<string, mixed> $params
 *
 * @return array{success: bool, error: string}
 */
function signnet_TestConnection(array $params): array
{
    return \SignNet\Whmcs\Module\ServerModule::testConnection($params);
}

/**
 * @param array<string, mixed> $params
 */
function signnet_CreateAccount(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::createAccount($params);
}

/**
 * @param array<string, mixed> $params
 */
function signnet_SuspendAccount(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::suspendAccount($params);
}

/**
 * @param array<string, mixed> $params
 */
function signnet_UnsuspendAccount(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::unsuspendAccount($params);
}

/**
 * @param array<string, mixed> $params
 */
function signnet_TerminateAccount(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::terminateAccount($params);
}

/**
 * @param array<string, mixed> $params
 */
function signnet_ChangePackage(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::changePackage($params);
}

/**
 * @return array<string, string>
 */
function signnet_AdminCustomButtonArray(): array
{
    return \SignNet\Whmcs\Module\ServerModule::adminCustomButtons();
}

/**
 * @param array<string, mixed> $params
 */
function signnet_refresh(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'refresh');
}

/**
 * @param array<string, mixed> $params
 */
function signnet_applyPlan(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'applyPlan');
}

/**
 * @param array<string, mixed> $params
 */
function signnet_approveOverAllowance(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'approveOverAllowance');
}

/**
 * @param array<string, mixed> $params
 */
function signnet_retryDomainAttach(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'retryDomainAttach');
}

/**
 * @param array<string, mixed> $params
 */
function signnet_resendOwnerInvite(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'resendOwnerInvite');
}

/**
 * @param array<string, mixed> $params
 */
function signnet_linkPortal(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'linkPortal');
}

/**
 * @param array<string, mixed> $params
 */
function signnet_unlinkPortal(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'unlinkPortal');
}

/**
 * @param array<string, mixed> $params
 *
 * @return array<string, string>
 */
function signnet_AdminServicesTabFields(array $params): array
{
    return \SignNet\Whmcs\Module\ServerModule::adminTabFields($params);
}

/**
 * @param array<string, mixed> $params
 *
 * @return array<string, mixed>
 */
function signnet_ClientArea(array $params): array
{
    return \SignNet\Whmcs\Module\ServerModule::clientArea($params);
}

/**
 * @return array<string, string>
 */
function signnet_ClientAreaAllowedFunctions(): array
{
    return \SignNet\Whmcs\Module\ServerModule::clientAreaAllowedFunctions();
}

/**
 * @param array<string, mixed> $params
 */
function signnet_checkDomain(array $params): string
{
    return \SignNet\Whmcs\Module\ServerModule::customAction($params, 'checkDomain');
}
