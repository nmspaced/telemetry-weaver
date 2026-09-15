<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientLogsExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FailingLogRecordExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Log-exporter scenarios split out of {@see ResilientExporterTest}. Span/metric exporter
 * scenarios live in {@see ResilientTracesExporterTest} and {@see ResilientMetricsExporterTest}.
 */
#[CoversClass(ResilientLogsExporter::class)]
#[CoversClass(ExportFailureReporter::class)]
final class ResilientLogsExporterTest extends TestCase
{
    #[Test]
    public function aFailedLogExportIsReportedAsAnExport(): void
    {
        $logger = new RecordingLogger();
        $exporter = new ResilientLogsExporter(
            FailingLogRecordExporter::rejecting(new \RuntimeException('connection reset')),
            new ExportFailureReporter($logger),
            Flushers::openGate(),
        );

        $exporter->export([])->await();

        self::assertSame('Failed to export log records', $logger->messageAt(0));
    }
}
