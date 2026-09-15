<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ProviderRegistry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
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
    public function requestMetricsRequireOptInAndWriterIdentity(): void
    {
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $runtime = SymfonyRuntimeProfile::fromKernel(0, true);
        $resource = ResourceInfo::create(Attributes::create(['host.name' => 'host-a', 'process.pid' => 42]));
        $policy = static fn(
            SymfonyRuntimeProfile $runtime,
            bool $delta,
            ResourceInfo $resource,
        ): RequestMetricPolicy => RequestMetricPolicy::forRuntime(
            $runtime,
            $delta ? 'delta' : 'disabled',
            $reporter,
            $resource,
        );
        self::assertFalse($policy($runtime, false, $resource)->allows());
        $delta = $policy($runtime, true, $resource);
        self::assertTrue($delta->allows());
        self::assertFalse($policy($runtime, true, ResourceInfo::emptyResource())->allows());
        self::assertSame(1, $reporter->total());
        $_SERVER['OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE'] = 'delta';
        try {
            // An explicitly set variable is an enum to the SDK: reading it must not throw.
            self::assertTrue($delta->allows(), 'an explicit delta preference');
            $_SERVER['OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE'] = 'LowMemory';
            self::assertTrue($delta->allows(), 'lowmemory keeps counters and histograms delta');
            $_SERVER['OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE'] = 'cumulative';
            self::assertFalse($delta->allows());
            self::assertTrue($policy(SymfonyRuntimeProfile::fromKernel(0, false), false, $resource)->allows());
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
        $policy = RequestMetricPolicy::forRuntime(
            SymfonyRuntimeProfile::fromKernel(0, true),
            'delta',
            $reporter,
            ResourceInfo::emptyResource(),
        );
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
                // No previous observation exists in a pipeline built for this request: as delta, the
                // whole observed total would be reported as this request's increment.
                'observable' => Temporality::CUMULATIVE,
            ],
            $temporalities,
        );
        self::assertTrue($gate->isClosed());
        self::assertSame(0, $reporter->total());
        unset($observable);
    }
}
