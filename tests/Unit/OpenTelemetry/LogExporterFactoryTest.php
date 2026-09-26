<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientLogsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LogRecordExporterFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Logs follow the same rule as spans: a non-OTLP exporter still comes back wrapped. */
#[CoversClass(LogRecordExporterFactory::class)]
final class LogExporterFactoryTest extends TestCase
{
    private mixed $previous = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->previous = $_SERVER[Variables::OTEL_LOGS_EXPORTER] ?? null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_SERVER[Variables::OTEL_LOGS_EXPORTER]);

            return;
        }

        $_SERVER[Variables::OTEL_LOGS_EXPORTER] = $this->previous;
    }

    /** @throws \RuntimeException */
    #[Test]
    public function aNonOtlpLogExporterComesFromTheRegistryAndIsStillWrapped(): void
    {
        $_SERVER[Variables::OTEL_LOGS_EXPORTER] = 'memory';
        $gate = Flushers::openGate();

        self::assertInstanceOf(
            ResilientLogsExporter::class,
            new LogRecordExporterFactory(
                new ResilientExporters(new ExportFailureReporter(new RecordingLogger()), $gate),
                new BudgetedOtlpTransports($gate),
            )->create(),
        );
    }
}
