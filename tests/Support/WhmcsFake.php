<?php

declare(strict_types=1);

namespace SignNet\Tests\Support;

/**
 * What the plugin told WHMCS during a test, and how WHMCS's internal API answers.
 */
final class WhmcsFake
{
    public const TOKEN = 'csrf-token';

    /**
     * @var list<array{module: string, action: string, request: string, response: string, processed: string}>
     */
    public static array $moduleCalls = [];

    /**
     * @var list<array{message: string, userId: int}>
     */
    public static array $activity = [];

    /**
     * @var list<array{command: string, values: array<string, mixed>}>
     */
    public static array $apiCalls = [];

    /**
     * @var array<string, \Closure(array<string, mixed>): array<string, mixed>>
     */
    public static array $apiHandlers = [];

    /**
     * @var list<array{hook: string, priority: int, callback: callable}>
     */
    public static array $hooks = [];

    public static bool $tokenValid = true;

    public static function reset(): void
    {
        self::$moduleCalls = [];
        self::$activity = [];
        self::$apiCalls = [];
        self::$apiHandlers = [];
        self::$hooks = [];
        self::$tokenValid = true;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function localApi(string $command, array $values): array
    {
        self::$apiCalls[] = ['command' => $command, 'values' => $values];
        $handler = self::$apiHandlers[$command] ?? null;

        return $handler === null ? ['result' => 'success'] : $handler($values);
    }

    /**
     * Records the call as WHMCS stores it: every replaceVars value masked in what is kept.
     *
     * @param array<mixed>|string $request
     * @param array<mixed>|string $response
     * @param array<mixed>|string $processed
     * @param list<string> $replaceVars
     */
    public static function logModuleCall(
        string $module,
        string $action,
        array|string $request,
        array|string $response,
        array|string $processed,
        array $replaceVars,
    ): void {
        $mask = static function (array|string $value) use ($replaceVars): string {
            $text = is_string($value) ? $value : (string) json_encode($value);
            foreach ($replaceVars as $secret) {
                if ($secret !== '') {
                    $text = str_replace($secret, str_repeat('*', strlen($secret)), $text);
                }
            }

            return $text;
        };
        self::$moduleCalls[] = [
            'module' => $module,
            'action' => $action,
            'request' => $mask($request),
            'response' => $mask($response),
            'processed' => $mask($processed),
        ];
    }

    /**
     * Everything the module log kept, as one string.
     */
    public static function moduleLogText(): string
    {
        return implode("\n", array_map(
            static fn (array $call): string => implode("\n", $call),
            self::$moduleCalls,
        ));
    }

    /**
     * @return list<string>
     */
    public static function activityMessages(): array
    {
        return array_map(static fn (array $entry): string => $entry['message'], self::$activity);
    }
}
