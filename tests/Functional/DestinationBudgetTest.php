<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Functional;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\Symfony;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The starvation this prevents was measured with exactly this shape: traces to a collector that
 * accepts and does not answer consumed the whole budget, and logs and metrics never reached a
 * healthy collector on the same flush.
 */
final class DestinationBudgetTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<resource> */
    private array $pipes = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            \proc_terminate($process);
        }

        foreach ($this->pipes as $pipe) {
            \fclose($pipe);
        }

        foreach ($this->processes as $process) {
            \proc_close($process);
        }

        Discovery::reset();
        unset($_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL]);
    }

    /** @throws \RuntimeException */
    #[Test]
    public function aHungCollectorDoesNotStarveSignalsBoundForAHealthyOne(): void
    {
        [$hung] = $this->collector('slow-collector.php');
        [$healthy, $requests] = $this->collector('recording-collector.php');
        $_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL] = 'http/json';
        Discovery::setDiscoverers([Symfony::class]);
        $budget = new FlushBudget(600);
        $factory = new BudgetedOtlpTransports(ExportGate::forBudget($budget))->forProtocol('http/json');
        $traces = $factory->create('http://' . $hung . '/v1/traces', 'application/json', timeout: 10);
        $logs = $factory->create('http://' . $hung . '/v1/logs', 'application/json', timeout: 10);
        $metrics = $factory->create('http://' . $healthy . '/v1/metrics', 'application/json', timeout: 10);

        $budget->begin(final: true);
        $started = \hrtime(true);
        try {
            self::assertFails($traces);
            $afterTraces = (\hrtime(true) - $started) / 1e9;
            self::assertFails($logs);
            $afterLogs = (\hrtime(true) - $started) / 1e9;
            $metrics->send('{}')->await();
        } finally {
            $budget->end();
        }

        $elapsed = (\hrtime(true) - $started) / 1e9;
        self::assertGreaterThan(0.2, $afterTraces, 'the hung collector was given its share');
        self::assertLessThan(0.45, $afterTraces, 'two destinations: about 300 ms of 600 each');
        self::assertLessThan(0.05, $afterLogs - $afterTraces, 'logs to the same collector must not wait again');
        self::assertLessThan(0.6, $elapsed);
        \stream_set_timeout($requests, 2);
        self::assertSame('/v1/metrics', \trim((string) \fgets($requests)));
    }

    /**
     * @return array{string, resource} the collector's address and its stdout
     *
     * @throws \RuntimeException
     */
    private function collector(string $fixture): array
    {
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/Fixtures/' . $fixture],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->processes[] = $process;
        foreach ($pipes as $pipe) {
            $this->pipes[] = $pipe;
        }

        $output = $pipes[1] ?? self::fail('Missing collector stdout');
        \stream_set_timeout($output, 5);
        $address = \fgets($output);
        self::assertIsString($address);

        return [\trim($address), $output];
    }

    /** @param TransportInterface<string> $transport */
    private static function assertFails(TransportInterface $transport): void
    {
        try {
            $transport->send('{}')->await();
        } catch (\Throwable $throwable) {
            self::assertNotSame('', $throwable->getMessage());

            return;
        }

        self::fail('The send to the hung collector must fail');
    }
}
