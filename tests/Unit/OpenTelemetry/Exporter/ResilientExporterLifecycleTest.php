<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientLogsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FailingLifecycleExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FailingLifecycleMetricExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `shutdown()` and `forceFlush()` on the three resilient exporters.
 *
 * The existing per-signal tests cover `export()`, which is the call that happens while a
 * request is alive. These two are the ones a *boundary* makes, and they run at the worst
 * moment: a worker stopping, a console command terminating, PHP shutting down. An exception
 * escaping there does not lose one batch, it takes the process with it — and the collector
 * being gone is exactly when they throw.
 *
 * The other half is the gate. A closed gate must refuse before the delegate is reached, not
 * after: once a pipeline is sealed, a batch processor draining late or a user calling
 * `shutdown()` a second time has to stop here rather than at a collector that is no longer
 * being waited for. The call counters are what tell "refused" apart from "called and ignored".
 */
#[CoversClass(ResilientLogsExporter::class)]
#[CoversClass(ResilientTracesExporter::class)]
#[CoversClass(ResilientMetricsExporter::class)]
#[CoversClass(ExportGate::class)]
final class ResilientExporterLifecycleTest extends TestCase
{
    #[Test]
    public function aFailingLogsLifecycleIsReportedAndAnsweredWithFalse(): void
    {
        $logger = new RecordingLogger();
        $exporter = new ResilientLogsExporter(
            new FailingLifecycleExporter(),
            new ExportFailureReporter($logger),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->shutdown());
        self::assertFalse($exporter->forceFlush());
        self::assertSame('Failed to shut down logs exporter', $logger->messageAt(0));
        self::assertSame('Failed to force flush logs exporter', $logger->messageAt(1));
    }

    #[Test]
    public function aFailingTracesLifecycleIsReportedAndAnsweredWithFalse(): void
    {
        $logger = new RecordingLogger();
        $exporter = new ResilientTracesExporter(
            new FailingLifecycleExporter(),
            new ExportFailureReporter($logger),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->shutdown());
        self::assertFalse($exporter->forceFlush());
        self::assertSame('Failed to shut down spans exporter', $logger->messageAt(0));
        self::assertSame('Failed to force flush spans exporter', $logger->messageAt(1));
    }

    #[Test]
    public function aFailingMetricsLifecycleIsReportedAndAnsweredWithFalse(): void
    {
        $logger = new RecordingLogger();
        $exporter = new ResilientMetricsExporter(
            new FailingLifecycleMetricExporter(),
            new ExportFailureReporter($logger),
            Flushers::openGate(),
        );

        self::assertFalse($exporter->shutdown());
        self::assertFalse($exporter->forceFlush());
        self::assertSame('Failed to shut down metrics exporter', $logger->messageAt(0));
        self::assertSame('Failed to force flush metrics exporter', $logger->messageAt(1));
    }

    /**
     * Nothing reaches the delegate once the gate is closed — not the export, not the
     * lifecycle. The delegate here throws on both lifecycle calls, so reaching it would fail
     * the test twice over: through the counter and through the report.
     */
    #[Test]
    public function aClosedGateRefusesEveryCallWithoutReachingTheExporter(): void
    {
        $logger = new RecordingLogger();
        $gate = Flushers::openGate();
        $gate->close();

        $logs = new FailingLifecycleExporter();
        $spans = new FailingLifecycleExporter();
        $metrics = new FailingLifecycleMetricExporter();

        $logsExporter = new ResilientLogsExporter($logs, new ExportFailureReporter($logger), $gate);
        $spansExporter = new ResilientTracesExporter($spans, new ExportFailureReporter($logger), $gate);
        $metricsExporter = new ResilientMetricsExporter($metrics, new ExportFailureReporter($logger), $gate);

        self::assertFalse($logsExporter->export([])->await());
        self::assertFalse($logsExporter->shutdown());
        self::assertFalse($logsExporter->forceFlush());
        self::assertFalse($spansExporter->export([])->await());
        self::assertFalse($spansExporter->shutdown());
        self::assertFalse($spansExporter->forceFlush());
        self::assertFalse($metricsExporter->export([]));
        self::assertFalse($metricsExporter->shutdown());
        self::assertFalse($metricsExporter->forceFlush());

        self::assertSame(0, $logs->calls() + $spans->calls() + $metrics->calls());
        self::assertSame([], $logger->messages(), 'a refusal is the design, not a failure to report');
    }
}
