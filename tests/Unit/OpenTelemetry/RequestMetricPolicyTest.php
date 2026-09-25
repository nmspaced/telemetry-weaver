<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestMetricPolicyTest extends TestCase
{
    #[Test]
    public function onlyTheModeDecidesWhetherARequestPipelineExports(): void
    {
        $fpm = SymfonyRuntimeProfile::fromKernel(0, true);

        self::assertFalse(RequestMetricPolicy::forRuntime($fpm, 'disabled')->allows());
        self::assertTrue(
            RequestMetricPolicy::forRuntime(SymfonyRuntimeProfile::fromKernel(1, true), 'disabled')->allows(),
        );
        self::assertTrue(
            RequestMetricPolicy::forRuntime(SymfonyRuntimeProfile::fromKernel(0, false), 'disabled')->allows(),
        );

        $_SERVER['OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE'] = 'cumulative';
        try {
            self::assertTrue(RequestMetricPolicy::forRuntime($fpm, 'delta')->allows());
        } finally {
            unset($_SERVER['OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE']);
        }
    }

    #[Test]
    public function finalizationExportsEveryInstrumentOnceWithOnlyCountersAndHistogramsAsDelta(): void
    {
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $budget = new FlushBudget();
        $gate = ExportGate::forBudget($budget);
        $registry = new ProviderRegistry($gate, $reporter);
        $policy = RequestMetricPolicy::forRuntime(SymfonyRuntimeProfile::fromKernel(0, true), 'delta');
        $exporter = new InMemoryExporter();
        $provider = $registry->metrics(
            new MeterProviderFactory(
                ResourceInfo::emptyResource(),
                new ResilientMetricsExporter($exporter, $reporter, $gate, $policy->selector()),
            )->create(),
        );
        $meter = $provider->getMeter('test');
        $counter = $meter->createCounter('requests');
        $counter->add(3);
        $meter->createHistogram('duration')->record(0.1);
        $meter->createUpDownCounter('active')->add(1);
        $meter->createGauge('gauge')->record(99);
        $observable = $meter->createObservableCounter(
            'observable',
            null,
            null,
            [],
            static function (ObserverInterface $observer): void {
                $observer->observe(40);
            },
        );
        $flusher = new TelemetryFlusher($registry, $budget, $reporter, SymfonyRuntimeProfile::fromKernel(0, true));
        $flusher->atShutdown();
        $flusher->atShutdown();

        $counter->add(10);
        $flusher->atBoundary();

        $temporalities = [];
        foreach ($exporter->collect() as $metric) {
            $data = $metric->data;
            $temporalities[$metric->name] =
                $data instanceof Sum || $data instanceof Histogram ? $data->temporality : null;
        }

        self::assertSame(
            [
                'requests' => Temporality::DELTA,
                'duration' => Temporality::DELTA,
                'active' => Temporality::CUMULATIVE,
                'gauge' => null,
                'observable' => Temporality::CUMULATIVE,
            ],
            $temporalities,
        );
        self::assertTrue($gate->isClosed());
        self::assertSame(0, $reporter->total());
        unset($observable);
    }
}
