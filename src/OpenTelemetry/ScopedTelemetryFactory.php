<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * @internal Resolves a scope's tracer and meter when the scope is created; there is no scope cache or mutable request state.
 *
 * Whether a signal is on is decided by the providers the container hands in (`SignalTracerProvider`,
 * `SignalMeterProvider`). The no-op tracer provider becomes a `ContextOnlyOpener` rather than a
 * `SpanOpener` over a no-op tracer, which would activate a context scope for every span. The
 * context-only opener activates one only when the operation changes the context, through a
 * boundary or baggage. That keeps `operation()->baggage()` working with tracing off, because
 * baggage does not depend on spans. The type check runs once per scope, not per span.
 */
final readonly class ScopedTelemetryFactory implements TelemetryFactory
{
    public function __construct(
        private TracerProviderInterface $tracers,
        private MeterProviderInterface $meters,
        private ContextStorageInterface $contextStorage,
        private DurationRecorder $recorder,
        private InstrumentationFailureReporter $reporter,
    ) {}

    #[\Override]
    public function scope(string $name, ?string $version = null, ?string $schemaUrl = null): Telemetry
    {
        if ($name === '') {
            throw new \InvalidArgumentException('An instrumentation scope name must not be empty.');
        }

        $opener = new ContextOnlyOpener($this->contextStorage, $this->reporter);
        $meter = new NoopMeter();
        try {
            if (!$this->tracers instanceof NoopTracerProvider) {
                $opener = new SpanOpener(
                    $this->tracers->getTracer($name, $version, $schemaUrl),
                    $this->contextStorage,
                    $this->reporter,
                );
            }
        } catch (\Throwable $throwable) {
            $this->reporter->report('Tracer resolution failed', $name, $throwable);
        }

        try {
            $meter = $this->meters->getMeter($name, $version, $schemaUrl);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Meter resolution failed', $name, $throwable);
        }

        return new DefaultTelemetry(
            $opener,
            new SafeMetrics($meter, $this->reporter, $this->recorder),
            $this->reporter,
            new OtelBaggageReader(),
        );
    }
}
