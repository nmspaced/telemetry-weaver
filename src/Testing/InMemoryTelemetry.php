<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Testing;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Psr\Log\NullLogger;

/**
 * Test-only recorder using the production operation engine. Never registers Globals or replaces context storage.
 *
 * Finish all operations before reset/shutdown. Measurements are delta snapshots drained by measurements();
 * reset drains the outstanding delta as well, so existing instruments remain usable without old samples.
 * Exported data intentionally accumulates until read/reset: this helper must not be a production service.
 */
final readonly class InMemoryTelemetry implements Telemetry
{
    private function __construct(
        private Telemetry $delegate,
        private SpanExporter $spanExporter,
        private MetricExporter $metricExporter,
        private TracerProviderInterface $tracers,
        private MeterProviderInterface $meters,
    ) {}

    public static function create(string $scope = 'test'): self
    {
        if ($scope === '') {
            throw new \InvalidArgumentException('An instrumentation scope name must not be empty.');
        }

        $spans = new SpanExporter();
        $metrics = new MetricExporter(temporality: Temporality::DELTA);
        $tracers = new TracerProvider(new SimpleSpanProcessor($spans));
        $meters = MeterProvider::builder()->addReader(new ExportingReader($metrics))->build();
        $reporter = new InstrumentationFailureReporter(new NullLogger());
        $telemetry = new DefaultTelemetry(
            new SpanOpener($tracers->getTracer($scope), Context::storage(), $reporter),
            new SafeMetrics($meters->getMeter($scope), $reporter),
            $reporter,
            Context::storage(),
        );

        return new self($telemetry, $spans, $metrics, $tracers, $meters);
    }

    #[\Override]
    public function trace(string $name, \Closure $work, array $attributes = []): mixed
    {
        return $this->delegate->trace($name, $work, $attributes);
    }

    #[\Override]
    public function operation(string $name): Operation
    {
        return $this->delegate->operation($name);
    }

    #[\Override]
    public function metrics(): Metrics
    {
        return $this->delegate->metrics();
    }

    #[\Override]
    public function currentSpan(): Span
    {
        return $this->delegate->currentSpan();
    }

    /** @return list<SpanDataInterface> */
    public function spans(): array
    {
        /** @var list<SpanDataInterface> */
        return \array_values($this->spanExporter->getSpans());
    }

    /** @return list<Metric> delta measurements since the preceding read or reset */
    public function measurements(): array
    {
        $this->meters->forceFlush();

        return \array_values($this->metricExporter->collect(true));
    }

    public function reset(): void
    {
        $this->spanExporter->getStorage()->exchangeArray([]);
        $this->measurements();
    }

    public function shutdown(): void
    {
        $this->tracers->shutdown();
        $this->meters->shutdown();
    }
}
