<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\Context\FiberBoundContextStorageExecutionAwareBC;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gives each test its own context storage and restores the global one afterwards.
 *
 * @internal
 */
abstract class TelemetryTestCase extends TestCase
{
    protected ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    protected InMemoryExporter $exporter;

    protected TracerProvider $provider;

    protected TracerInterface $tracer;

    protected RecordingLogger $logger;

    protected InstrumentationFailureReporter $reporter;

    protected ContextStorageInterface $contextStorage;

    protected SpanOpener $spans;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousStorage = Context::storage();
        Context::setStorage(new ContextStorage());
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->tracer = $this->provider->getTracer('test');
        $this->logger = new RecordingLogger();
        $this->reporter = new InstrumentationFailureReporter($this->logger);
        $this->contextStorage = Context::storage();
        $this->spans = new SpanOpener($this->tracer, $this->contextStorage, $this->reporter);
    }

    #[\Override]
    protected function tearDown(): void
    {
        while (($scope = Context::storage()->scope()) !== null) {
            $scope->detach();
        }

        $this->provider->shutdown();
        Context::setStorage($this->previousStorage);
    }

    /** Switches to the SDK's default fiber-aware storage, for tests about fibers. */
    protected function useFiberBoundStorage(): void
    {
        Context::setStorage(new FiberBoundContextStorageExecutionAwareBC());
        $this->contextStorage = Context::storage();
        $this->spans = new SpanOpener($this->tracer, $this->contextStorage, $this->reporter);
    }

    /**
     * Activates a span and leaves it, like leaking third-party instrumentation.
     *
     * @param non-empty-string $name
     */
    protected function leak(string $name): SpanInterface
    {
        $span = $this->tracer->spanBuilder($name)->startSpan();
        $span->storeInContext(Context::getCurrent())->activate();

        return $span;
    }

    /** @return list<ImmutableSpan> */
    protected function exported(): array
    {
        $spans = $this->exporter->getSpans();
        self::assertContainsOnlyInstancesOf(ImmutableSpan::class, $spans);

        return \array_values($spans);
    }

    /** One exported span, failing the test if it is missing. */
    protected function exportedSpan(int $index = 0): ImmutableSpan
    {
        $span = $this->exported()[$index] ?? null;
        self::assertInstanceOf(ImmutableSpan::class, $span, 'no exported span at index ' . $index);

        return $span;
    }

    /** @return list<string> the exported span names, in export order */
    protected function exportedNames(): array
    {
        return \array_map(static fn(ImmutableSpan $span): string => $span->getName(), $this->exported());
    }

    protected function firstEventName(ImmutableSpan $span): ?string
    {
        return ($span->getEvents()[0] ?? null)?->getName();
    }

    /** The trace active in this test's storage, or null. */
    protected function activeTrace(): ?TraceContext
    {
        return new OtelActiveTrace($this->contextStorage)->current();
    }

    protected function assertNoReports(): void
    {
        self::assertSame([], $this->logger->messages(), 'a clean run reports nothing');
    }
}
