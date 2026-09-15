<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Functional;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\BudgetedOtlpTransports;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\Symfony;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OtlpFlushTimeoutTest extends TestCase
{
    /** @throws \RuntimeException */
    #[Test]
    public function theRealHttpTransportStopsWaitingOnASlowCollector(): void
    {
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/Fixtures/slow-collector.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        $budget = new FlushBudget(100);
        $previous = $_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL] ?? null;
        $_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL] = 'http/json';
        Discovery::setDiscoverers([Symfony::class]);
        try {
            $output = $pipes[1] ?? self::fail('Missing collector stdout');
            \stream_set_timeout($output, 5);
            $address = \fgets($output);
            self::assertIsString($address);
            $transport = new BudgetedOtlpTransports(ExportGate::forBudget($budget))
                ->forProtocol('http/json')
                ->create('http://' . \trim($address) . '/v1/traces', 'application/json', timeout: 10);
            $budget->begin();
            $started = \hrtime(true);
            try {
                $transport->send('{}')->await();
                self::fail('The collector must outlive the deadline');
            } catch (\RuntimeException) {
                $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
                self::assertLessThan(
                    1.0,
                    $elapsed,
                    'the initial ten-second timeout must not survive in a cached client',
                );
            }

            $transport->shutdown();
        } finally {
            $budget->end();
            Discovery::reset();
            $_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL] = $previous;
            if ($previous === null) {
                unset($_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL]);
            }

            \proc_terminate($process);
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }

            \proc_close($process);
        }
    }
}
