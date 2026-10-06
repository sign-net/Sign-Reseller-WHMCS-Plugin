<?php

declare(strict_types=1);

namespace SignNet\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the plugin's own autoloader in a fresh PHP process, without Composer.
 */
final class AutoloaderTest extends TestCase
{
    private const SCRIPT = <<<'PHP'
        $before = count(spl_autoload_functions());
        require $argv[1];
        require $argv[1];
        echo json_encode([
            'registered' => count(spl_autoload_functions()) - $before,
            'client' => class_exists('SignNet\\ResellerApi\\ResellerClient'),
            'interface' => interface_exists('SignNet\\ResellerApi\\Http\\Transport'),
            'model' => class_exists('SignNet\\ResellerApi\\Model\\ProvisionRequest'),
            'missing' => class_exists('SignNet\\ResellerApi\\DoesNotExist'),
            'foreign' => class_exists('Vendor\\Package\\Thing'),
        ]);
        PHP;

    #[Test]
    public function itLoadsThePluginClassesAndRegistersOnlyOnce(): void
    {
        $autoload = dirname(__DIR__, 2) . '/modules/servers/signnet/lib/autoload.php';
        $process = proc_open(
            [PHP_BINARY, '-r', self::SCRIPT, $autoload],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::fail('Could not start a PHP process.');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $errors);
        self::assertSame(
            [
                'registered' => 1,
                'client' => true,
                'interface' => true,
                'model' => true,
                'missing' => false,
                'foreign' => false,
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
