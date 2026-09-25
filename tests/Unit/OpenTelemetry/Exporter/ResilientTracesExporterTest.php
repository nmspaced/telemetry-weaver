<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FailingSpanExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Fake\ThrowingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Span-exporter scenarios split out of {@see ResilientExporterTest}. Metric/log exporter scenarios
 * live in {@see ResilientMetricsExporterTest} and {@see ResilientLogsExporterTest}.
 */
#[CoversClass(ResilientTracesExporter::class)]
#[CoversClass(ExportFailureReporter::class)]
final class ResilientTracesExporterTest extends TestCase
{
    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function spanExporterSwallowsSynchronousFailures(): void
    {
        $exporter = new ResilientTracesExporter(
            FailingSpanExporter::throwing(new \RuntimeException('dns failure')),
            new ExportFailureReporter($this->logger),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->export([])->await());
        self::assertSame(1, $this->logger->count());
        self::assertSame('dns failure', $this->logger->contextAt(0)['exception'] ?? null);
    }

    #[Test]
    public function spanExporterSwallowsRejectedFutures(): void
    {
        $exporter = new ResilientTracesExporter(
            FailingSpanExporter::rejecting(new \RuntimeException('connection reset')),
            new ExportFailureReporter($this->logger),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->export([])->await());
        self::assertSame(1, $this->logger->count());
        self::assertSame('connection reset', $this->logger->contextAt(0)['exception'] ?? null);
    }

    #[Test]
    public function aFailedSpanExportIsReportedAsAnExport(): void
    {
        $exporter = new ResilientTracesExporter(
            FailingSpanExporter::throwing(new \RuntimeException('dns failure')),
            new ExportFailureReporter($this->logger),
            Flushers::openGate(),
        );

        $exporter->export([])->await();

        self::assertSame('Failed to export spans', $this->logger->messageAt(0));
    }

    #[Test]
    public function aBrokenLoggerDoesNotEscapeASynchronousFailure(): void
    {
        $exporter = new ResilientTracesExporter(
            FailingSpanExporter::throwing(new \RuntimeException('dns failure')),
            new ExportFailureReporter(new ThrowingLogger()),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->export([])->await());
    }

    #[Test]
    public function aBrokenLoggerDoesNotEscapeARejectedFuture(): void
    {
        $exporter = new ResilientTracesExporter(
            FailingSpanExporter::rejecting(new \RuntimeException('connection reset')),
            new ExportFailureReporter(new ThrowingLogger()),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->export([])->await());
    }
}
