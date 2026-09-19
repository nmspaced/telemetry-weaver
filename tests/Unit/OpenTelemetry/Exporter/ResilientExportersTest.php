<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricTemporality;
use Nmspaced\TelemetryWeaver\Tests\Fake\CountingSpanExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\InstrumentMetadata;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResilientExporters::class)]
final class ResilientExportersTest extends TestCase
{
    #[Test]
    public function aSealedPipelineRefusesExportsWhoeverBuiltTheExporter(): void
    {
        $gate = Flushers::openGate();
        $exporter = new CountingSpanExporter();
        $spans = new ResilientExporters(new ExportFailureReporter(new RecordingLogger()), $gate)->spans($exporter);

        $gate->close();

        self::assertFalse($spans->export([])->await());
        self::assertSame(0, $exporter->exported);
    }

    #[Test]
    public function theMetricSelectorDecidesTemporalityOverTheExportersOwn(): void
    {
        $metrics = self::exporters()->metrics(new InMemoryMetricExporter(), MetricTemporality::delta());

        self::assertSame(Temporality::DELTA, $metrics->temporality(new InstrumentMetadata(InstrumentType::COUNTER)));
    }

    #[Test]
    public function logsAreWrappedToo(): void
    {
        self::assertTrue(self::exporters()->logs(new InMemoryLogExporter())->forceFlush());
    }

    private static function exporters(): ResilientExporters
    {
        return new ResilientExporters(new ExportFailureReporter(new RecordingLogger()), Flushers::openGate());
    }
}
