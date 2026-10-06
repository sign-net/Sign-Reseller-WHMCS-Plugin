<?php

/**
 * PSR-4 autoloader for the plugin's own classes. The host application ships its own Composer
 * vendor directory, so the plugin loads its code without Composer at runtime.
 *
 * Safe to include more than once, including through different paths.
 */

declare(strict_types=1);

if (!defined('SIGNNET_LIB_AUTOLOADER_REGISTERED')) {
    define('SIGNNET_LIB_AUTOLOADER_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        $roots = [
            'SignNet\\ResellerApi\\' => __DIR__ . '/ResellerApi/',
            'SignNet\\Whmcs\\' => __DIR__ . '/Whmcs/',
        ];
        foreach ($roots as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    });
}
