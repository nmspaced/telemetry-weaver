<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Serializer\SerializerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Serializer\TraceableSerializer;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\ContextAwareDecoderInterface;
use Symfony\Component\Serializer\Encoder\ContextAwareEncoderInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * A real `TraceableSerializer` over an in-memory tracer and meter.
 *
 * @internal
 */
abstract class TraceableSerializerTestCase extends TestCase
{
    protected ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    protected InMemoryExporter $spans;

    protected TracerProvider $tracers;

    protected InMemoryMetricExporter $metrics;

    protected MeterProviderInterface $meters;

    protected RecordingLogger $logger;

    protected ?SpanInterface $parent = null;

    protected ?ScopeInterface $parentScope = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousStorage = Context::storage();
        Context::setStorage(new ContextStorage());

        $this->spans = new InMemoryExporter();
        $this->tracers = new TracerProvider(new SimpleSpanProcessor($this->spans));

        $this->metrics = new InMemoryMetricExporter();
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->metrics))->build();

        $this->logger = new RecordingLogger();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->parentScope?->detach();
        $this->parent?->end();

        while (($scope = Context::storage()->scope()) !== null) {
            $scope->detach();
        }

        $this->meters->shutdown();
        $this->tracers->shutdown();
        Context::setStorage($this->previousStorage);
    }

    protected function activateParent(): void
    {
        $this->parent = $this->tracers->getTracer('test')->spanBuilder('process message')->startSpan();
        $this->parentScope = $this->parent->activate();
    }

    protected function serializer(
        ?FrozenClock $clock = null,
        (SerializerInterface&NormalizerInterface&DenormalizerInterface&ContextAwareEncoderInterface&ContextAwareDecoderInterface)|null $inner = null,
    ): TraceableSerializer {
        $reporter = new InstrumentationFailureReporter($this->logger);

        $telemetry = new SerializerTelemetry(TelemetryFactory::create(
            $this->meters->getMeter('test'),
            new SpanOpener($this->tracers->getTracer('test'), Context::storage(), $reporter),
            $reporter,
            $clock ?? new FrozenClock(),
        ));

        return new TraceableSerializer($inner ?? $this->inner(), $telemetry);
    }

    protected function inner(): SerializerInterface&NormalizerInterface&DenormalizerInterface&ContextAwareEncoderInterface&ContextAwareDecoderInterface
    {
        return new Serializer([new DateTimeNormalizer()], [new JsonEncoder()]);
    }

    /** @return list<string> */
    protected function exportedNames(): array
    {
        return \array_map(
            static fn(ImmutableSpan $span): string => $span->getName(),
            \array_values($this->spans->getSpans()),
        );
    }

    protected function exportedSpan(int $index = 0): ImmutableSpan
    {
        $span = \array_values($this->spans->getSpans())[$index] ?? null;
        self::assertInstanceOf(ImmutableSpan::class, $span, 'no exported span at index ' . $index);

        return $span;
    }

    /** @return list<string> */
    protected function recordedMetricNames(): array
    {
        return \array_map(static fn(Metric $metric): string => $metric->name, \array_values($this->metrics->collect()));
    }

    /** @param non-empty-string $name */
    protected function histogram(string $name): HistogramDataPoint
    {
        foreach ($this->metrics->collect() as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            $point = MetricPoints::first($metric);
            self::assertInstanceOf(HistogramDataPoint::class, $point);

            return $point;
        }

        Assert::fail(\sprintf('no "%s" metric was recorded', $name));
    }
}
