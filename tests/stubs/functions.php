<?php

/**
 * The WHMCS global functions the plugin calls, recording into WhmcsFake instead of a WHMCS
 * installation. Loaded through Composer's autoload-dev, so only tests and static analysis see them.
 */

declare(strict_types=1);

use SignNet\Tests\Support\WhmcsFake;

/**
 * @param array<string, mixed> $postData
 *
 * @return array<string, mixed>
 */
function localAPI(string $command, array $postData = [], string $adminUsername = ''): array
{
    return WhmcsFake::localApi($command, $postData);
}

/**
 * @param array<mixed>|string $requestString
 * @param array<mixed>|string $responseData
 * @param array<mixed>|string $processedData
 * @param list<string> $replaceVars
 */
function logModuleCall(
    string $module,
    string $action,
    array|string $requestString,
    array|string $responseData,
    array|string $processedData = '',
    array $replaceVars = [],
): void {
    WhmcsFake::logModuleCall($module, $action, $requestString, $responseData, $processedData, $replaceVars);
}

function logActivity(string $message, int $userId = 0): void
{
    WhmcsFake::$activity[] = ['message' => $message, 'userId' => $userId];
}

function encrypt(string $string): string
{
    return 'enc:' . base64_encode($string);
}

function decrypt(string $string): string
{
    if (!str_starts_with($string, 'enc:')) {
        return '';
    }
    $decoded = base64_decode(substr($string, 4), true);

    return $decoded === false ? '' : $decoded;
}

function check_token(string $type = 'WHMCS.default'): void
{
    if (!WhmcsFake::$tokenValid) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function generate_token(string $type = 'form'): string
{
    return $type === 'plain'
        ? WhmcsFake::TOKEN
        : '<input type="hidden" name="token" value="' . WhmcsFake::TOKEN . '" />';
}

function add_hook(string $hookPoint, int $priority, callable $function): void
{
    WhmcsFake::$hooks[] = ['hook' => $hookPoint, 'priority' => $priority, 'callback' => $function];
}
