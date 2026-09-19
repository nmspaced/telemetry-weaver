<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Testing;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryOperation;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\ActiveTraceIdentity;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTraceIdentity;
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
 * Test-only recorder using the production operation engine. Never registers Globals or replaces context storage.
 *
 * Finish all operations before reset/shutdown. Measurements are delta snapshots drained by measurements();
 * reset drains the outstanding delta as well, so existing instruments remain usable without old samples.
 * Exported data intentionally accumulates until read/reset: this helper must not be a production service.
 *
 * It stands in for the facade on both sides of the package: an application drives it through
 * the public {@see Telemetry} contract, and the bundle's own instrumentation — which needs
 * the wider boundary constructor — can be driven through the same recorder in a test rather
 * than against a second, differently-behaving double.
 */
// @mago-expect lint:too-many-methods — it mirrors the telemetry facade plus the recorder's own readers; splitting either half would make a test double harder to find than to use
final readonly class InMemoryTelemetry implements BoundaryTelemetry
{
    // @mago-expect lint:excessive-parameter-list — the recorder owns one pipeline per signal
    // plus the read side of each; the constructor is private and has a single call site.
    private function __construct(
        private BoundaryTelemetry $delegate,
        private SpanExporter $spanExporter,
        private MetricExporter $metricExporter,
        private TracerProviderInterface $tracers,
        private MeterProviderInterface $meters,
        private ActiveTraceIdentity $activeTrace,
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

        return new self(
            $telemetry,
            $spans,
            $metrics,
            $tracers,
            $meters,
            new OtelActiveTraceIdentity(Context::storage()),
        );
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
     * @internal for the bundle's own instrumentation tests; an application has no use for it
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
     * The trace running right now, or null when nothing is.
     *
     * The assertion an ownership bug fails: after a unit of work, nothing may still be
     * active. The public API deliberately offers no way to read ambient state — that is
     * what is being tested, so the test double reads it instead of the code under test.
     *
     * @return array{trace_id: non-empty-string, span_id: non-empty-string, trace_flags: int}|null
     */
    public function activeTrace(): ?array
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
