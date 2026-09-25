<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Testing;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryOperation;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
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
 * An in-memory recorder for tests, running the production operation engine.
 *
 * Data accumulates until read or reset; never use it as a production service. Finish all
 * operations before `reset()` or `shutdown()`.
 */
// @mago-expect lint:too-many-methods — facade plus recorder readers
final readonly class InMemoryTelemetry implements BoundaryTelemetry
{
    // @mago-expect lint:excessive-parameter-list — one pipeline per signal; single call site
    private function __construct(
        private BoundaryTelemetry $delegate,
        private SpanExporter $spanExporter,
        private MetricExporter $metricExporter,
        private TracerProviderInterface $tracers,
        private MeterProviderInterface $meters,
        private ActiveTrace $activeTrace,
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
            new SafeMetrics($meters->getMeter($scope), $reporter, new OtelDurationRecorder()),
            $reporter,
            new OtelBaggageReader(),
        );

        return new self($telemetry, $spans, $metrics, $tracers, $meters, new OtelActiveTrace(Context::storage()));
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

    /**
     * @internal for the bundle's own instrumentation tests
     */
    #[\Override]
    public function boundary(string $name): BoundaryOperation
    {
        return $this->delegate->boundary($name);
    }

    #[\Override]
    public function metrics(): Metrics
    {
        return $this->delegate->metrics();
    }

    /**
     * The trace running right now, or null. Null after a unit of work means nothing leaked.
     */
    public function activeTrace(): ?TraceContext
    {
        return $this->activeTrace->current();
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
