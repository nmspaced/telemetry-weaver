<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelIncomingTrace;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Assert;

/**
 * Builds a real `DefaultTelemetry` over the shared `SpanOpener`/context storage from
 * {@see TelemetryTestCase} plus a fresh `SafeMetrics`, letting every `PublicTelemetryTest`
 * split carry only the scenarios specific to it.
 *
 * @internal
 */
abstract class PublicTelemetryTestCase extends TelemetryTestCase
{
    protected MeterProviderInterface $meters;

    protected InMemoryExporter $metricExporter;

    protected FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock();
        $this->metricExporter = new InMemoryExporter(temporality: Temporality::CUMULATIVE);
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->metricExporter))->build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->meters->shutdown();
        parent::tearDown();
    }

    /**
     * @param bool $traces false is what `traces.enabled: false` builds: no spans, but the
     *                     same context handling, so baggage and incoming traces still apply
     */
    protected function telemetry(bool $traces = true, bool $metrics = true): DefaultTelemetry
    {
        return new DefaultTelemetry(
            $traces ? $this->spans : $this->spans->suppressed(),
            new SafeMetrics(
                $metrics ? $this->meters->getMeter('test') : new NoopMeter(),
                $this->reporter,
                new OtelDurationRecorder(),
                $this->clock,
            ),
            $this->reporter,
            new OtelBaggageReader(),
        );
    }

    /**
     * An incoming trace that carried nothing — how a boundary asks for a new trace rather
     * than a continuation of whatever the process is already doing.
     */
    protected function rootTrace(): IncomingTrace
    {
        return OtelIncomingTrace::none();
    }

    /**
     * The trace an operation ran in, as an incoming one — what a link needs, and what a real
     * boundary would have extracted from a carrier.
     *
     * Ids rather than a span, because that is all a carrier ever holds, and because the ids
     * have to be read while the operation is still running: a finished one has released its
     * view and reports none.
     *
     * @param non-empty-string|null $traceId
     * @param non-empty-string|null $spanId
     */
    protected function traceOf(?string $traceId, ?string $spanId): IncomingTrace
    {
        $context =
            $traceId === null || $spanId === null ? SpanContext::getInvalid() : SpanContext::create($traceId, $spanId);

        return OtelIncomingTrace::extracted(OtelSpan::wrap($context)->storeInContext(Context::getRoot()));
    }

    protected function duration(Telemetry $telemetry): Duration
    {
        return $telemetry->metrics()->duration('duration', DurationUnit::Seconds, [0.1, 0.5, 1]);
    }

    protected function metric(): Metric
    {
        $this->meters->forceFlush();

        return $this->metricExporter->collect(true)[0] ?? Assert::fail('no metric was recorded');
    }

    protected function metricPoint(): HistogramDataPoint
    {
        return MetricPoints::firstHistogram($this->metric());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function signals(): iterable
    {
        yield 'both' => [true, true];
        yield 'traces' => [true, false];
        yield 'metrics' => [false, true];
        yield 'neither' => [false, false];
    }
}
