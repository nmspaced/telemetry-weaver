<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\RateLimiter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\Metrics;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingMetricExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\ThrowingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Metrics-exporter scenarios split out of {@see ResilientExporterTest}. Span/log exporter
 * scenarios live in {@see ResilientTracesExporterTest} and {@see ResilientLogsExporterTest}.
 */
#[CoversClass(ResilientMetricsExporter::class)]
#[CoversClass(ExportFailureReporter::class)]
#[CoversClass(RateLimiter::class)]
final class ResilientMetricsExporterTest extends TestCase
{
    private RecordingLogger $logger;

    private FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->clock = new FrozenClock();
    }

    #[Test]
    public function metricExporterSwallowsExceptionsAndLogsThem(): void
    {
        $exporter = new ResilientMetricsExporter(
            new RecordingMetricExporter(throws: new \RuntimeException('collector unreachable')),
            new ExportFailureReporter($this->logger),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->export(Metrics::batch(['a'])), 'failure does not reach the application');
        self::assertSame(1, $this->logger->count());
        self::assertSame('collector unreachable', $this->logger->contextAt(0)['exception'] ?? null);
    }

    #[Test]
    public function metricExporterPassesSuccessThrough(): void
    {
        $delegate = new RecordingMetricExporter();
        $exporter = new ResilientMetricsExporter(
            $delegate,
            new ExportFailureReporter($this->logger),
            Flushers::openGate(),
        );

        self::assertTrue($exporter->export(Metrics::batch(['a'])));
        self::assertSame([['a']], $delegate->batches);
        self::assertSame(0, $this->logger->count());
    }

    #[Test]
    public function metricExporterKeepsForceFlushReachable(): void
    {
        $delegate = new RecordingMetricExporter();
        $exporter = new ResilientMetricsExporter(
            $delegate,
            new ExportFailureReporter($this->logger),
            Flushers::openGate(),
        );

        self::assertTrue($exporter->forceFlush());
        self::assertSame(1, $delegate->flushes, 'the wrapper must not lose the delegate forceFlush');
    }

    /**
     * A flood of failures must not become its own incident in the log.
     *
     * Once the pause elapses, the next failure passes through again and
     * carries the accumulated count.
     */
    #[Test]
    public function repeatedFailuresAreRateLimited(): void
    {
        $exporter = new ResilientMetricsExporter(
            new RecordingMetricExporter(throws: new \RuntimeException('down')),
            new ExportFailureReporter(
                $this->logger,
                detailedPerProcess: 2,
                minIntervalSeconds: 60.0,
                clock: $this->clock,
            ),
            Flushers::openGate(),
        );

        for ($i = 0; $i < 10; ++$i) {
            $exporter->export(Metrics::batch(['a']));
        }

        self::assertSame(2, $this->logger->count(), 'only the first two of ten failures are logged');

        $this->clock->advanceSeconds(61.0);
        $exporter->export(Metrics::batch(['a']));

        self::assertSame(3, $this->logger->count());
        self::assertSame(11, $this->logger->contextAt(2)['failures_so_far'] ?? null, 'suppressed ones are counted too');
    }

    #[Test]
    public function aBrokenLoggerDoesNotEscapeAMetricExport(): void
    {
        $exporter = new ResilientMetricsExporter(
            new RecordingMetricExporter(throws: new \RuntimeException('down')),
            new ExportFailureReporter(new ThrowingLogger()),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->export(Metrics::batch(['a'])));
    }
}
