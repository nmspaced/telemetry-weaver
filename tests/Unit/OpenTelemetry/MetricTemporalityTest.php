<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricTemporality;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\Tests\Fake\InstrumentMetadata;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricTemporality::class)]
#[CoversClass(MetricExporterFactory::class)]
final class MetricTemporalityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function specification(): iterable
    {
        $d = Temporality::DELTA;
        $c = Temporality::CUMULATIVE;
        // instrument => cumulative, delta, lowmemory
        $table = [
            [InstrumentType::COUNTER,                      $c, $d, $d],
            [InstrumentType::HISTOGRAM,                    $c, $d, $d],
            [InstrumentType::ASYNCHRONOUS_COUNTER,         $c, $d, $c],
            [InstrumentType::UP_DOWN_COUNTER,              $c, $c, $c],
            [InstrumentType::ASYNCHRONOUS_UP_DOWN_COUNTER, $c, $c, $c],
            [InstrumentType::GAUGE,                        $c, $c, $c],
            [InstrumentType::ASYNCHRONOUS_GAUGE,           $c, $c, $c],
            ['InstrumentKindFromAFutureSdk',               $c, $c, $c],
        ];

        foreach ($table as [$instrument, $cumulative, $delta, $lowMemory]) {
            yield 'cumulative ' . $instrument => ['cumulative', $instrument, $cumulative];
            yield 'delta ' . $instrument => ['delta', $instrument, $delta];
            yield 'lowmemory ' . $instrument => ['lowmemory', $instrument, $lowMemory];
        }
    }

    /** @throws \UnexpectedValueException */
    #[Test]
    #[DataProvider('specification')]
    public function eachInstrumentKindGetsTheSpecificationsTemporalityAndNeverNull(
        string $preference,
        string $instrument,
        string $expected,
    ): void {
        // A synchronous stream's own temporality is DELTA: the selector must not fall back to it.
        $metadata = new InstrumentMetadata($instrument, Temporality::DELTA);

        self::assertSame($expected, MetricTemporality::preferred($preference)->temporality($metadata));
    }

    /** @throws \UnexpectedValueException */
    #[Test]
    public function thePreferenceIsReadCaseInsensitivelyAndAnUnknownOneIsRejected(): void
    {
        $metadata = new InstrumentMetadata(InstrumentType::COUNTER);
        self::assertSame(Temporality::DELTA, MetricTemporality::preferred('LowMemory')->temporality($metadata));

        $this->expectException(\UnexpectedValueException::class);
        MetricTemporality::preferred('sometimes');
    }

    /**
     * The installed OTLP factory turns `delta` into DELTA for every instrument. What reaches the
     * reader through the bundle's factory is the per-kind choice: state stays cumulative.
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function theOtlpExporterFromTheFactoryKeepsStateCumulativeUnderADeltaPreference(): void
    {
        $variables = [
            Variables::OTEL_METRICS_EXPORTER => 'otlp',
            Variables::OTEL_EXPORTER_OTLP_PROTOCOL => 'http/protobuf',
            Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE => 'delta',
        ];
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = $_SERVER[$name] ?? null;
            $_SERVER[$name] = $value;
        }

        try {
            $reporter = new ExportFailureReporter(new RecordingLogger());
            $gate = Flushers::openGate();
            $exporter = new MetricExporterFactory(
                new ResilientExporters($reporter, $gate),
                new BudgetedOtlpTransports($gate),
                RequestMetricPolicy::forRuntime(SymfonyRuntimeProfile::fromKernel(1, true), 'disabled'),
            )->create();
        } finally {
            foreach ($previous as $name => $value) {
                unset($_SERVER[$name]);
                if ($value !== null) {
                    $_SERVER[$name] = $value;
                }
            }
        }

        self::assertInstanceOf(ResilientMetricsExporter::class, $exporter);
        foreach ([
            InstrumentType::COUNTER => Temporality::DELTA,
            InstrumentType::HISTOGRAM => Temporality::DELTA,
            InstrumentType::UP_DOWN_COUNTER => Temporality::CUMULATIVE,
            InstrumentType::ASYNCHRONOUS_UP_DOWN_COUNTER => Temporality::CUMULATIVE,
            InstrumentType::ASYNCHRONOUS_GAUGE => Temporality::CUMULATIVE,
        ] as $kind => $expected) {
            self::assertSame($expected, $exporter->temporality(new InstrumentMetadata($kind)), $kind);
        }
    }
}
