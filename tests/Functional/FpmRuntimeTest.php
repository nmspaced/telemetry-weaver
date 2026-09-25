<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Functional;

use Nmspaced\TelemetryWeaver\Tests\Support\FastCgi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FpmRuntimeTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function requestsInOneFpmChildFinishWithinBudgetAndShutdownDoesNotExportAgain(): void
    {
        $binaries = \glob('/usr/sbin/php-fpm*');
        $configured = \getenv('PHP_FPM_BINARY');
        $binary = $configured === false ? $binaries[0] ?? '' : $configured;
        if (!\is_executable($binary)) {
            self::markTestSkipped('Set PHP_FPM_BINARY to run the real FPM lifecycle test.');
        }

        $directory = \sys_get_temp_dir() . '/otel-fpm-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($directory, 0o700));
        $socket = $directory . '/fpm.sock';
        \file_put_contents(
            $directory . '/fpm.conf',
            "[global]\nerror_log = {$directory}/error.log\ndaemonize = no\n[probe]\nlisten = {$socket}\npm = static\npm.max_children = 1\ncatch_workers_output = yes\n",
        );
        $pipes = [];
        $process = \proc_open(
            [$binary, '-F', '-y', $directory . '/fpm.conf'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/stderr', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            self::awaitFile($socket);
            $this->exerciseRequests($socket, $directory);
        } finally {
            \proc_terminate($process);
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }

            \proc_close($process);
            $files = \glob($directory . '/*');
            foreach ($files === false ? [] : $files as $file) {
                \unlink($file);
            }

            \rmdir($directory);
        }
    }

    private function exerciseRequests(string $socket, string $directory): void
    {
        $pipes = [];
        $collector = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/Fixtures/slow-collector.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($collector);
        try {
            $stdout = $pipes[1] ?? self::fail('Missing collector stdout');
            \stream_set_timeout($stdout, 3);
            $address = \fgets($stdout);
            self::assertIsString($address);
            $script = \dirname(__DIR__) . '/Fixtures/fpm-runtime.php';
            $started = \hrtime(true);
            $first = FastCgi::request($socket, $script, \http_build_query([
                'directory' => $directory,
                'endpoint' => 'http://' . \trim($address) . '/v1/traces',
            ]));
            $second = FastCgi::request($socket, $script, \http_build_query(['directory' => $directory]));
            self::assertLessThan(1.0, (\hrtime(true) - $started) / 1_000_000_000);
            self::assertSame($first, $second, 'The same FPM child keeps its standard SDK resource across requests');
            self::assertStringNotContainsString('service.instance.id', $first);
            self::awaitFile($directory . '/shutdown');
            self::assertSame('closed', \file_get_contents($directory . '/shutdown'));
            \unlink($directory . '/shutdown');
            FastCgi::request($socket, $script, \http_build_query([
                'directory' => $directory,
                'mode' => 'exit',
                'endpoint' => 'http://' . \trim($address) . '/v1/traces',
            ]));
            $started = \hrtime(true);
            FastCgi::request($socket, $script, \http_build_query(['directory' => $directory]));
            self::assertLessThan(
                1.0,
                (\hrtime(true) - $started) / 1_000_000_000,
                'exit fallback must discard without network I/O',
            );
            self::awaitFile($directory . '/shutdown');
            self::assertSame('closed', \file_get_contents($directory . '/shutdown'));
        } finally {
            \proc_terminate($collector);
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }

            \proc_close($collector);
        }
    }

    private static function awaitFile(string $path): void
    {
        $deadline = \microtime(true) + 3;
        do {
            \clearstatcache(true, $path);
            if (\file_exists($path)) {
                return;
            }

            \usleep(10_000);
        } while (\microtime(true) < $deadline);

        self::fail('FPM did not create ' . $path);
    }
}
