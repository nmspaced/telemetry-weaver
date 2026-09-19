<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\WorkerFlushSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * `messenger:consume` never dispatches `kernel.terminate`, so without this subscriber a
 * worker's telemetry waits for the process to exit — and is lost if it is killed first.
 */
#[CoversClass(WorkerFlushSubscriber::class)]
#[CoversClass(SignalFlusher::class)]
final class WorkerFlushTest extends TestCase
{
    private InMemorySpanExporter $spans;

    private TracerProvider $tracers;

    private InMemoryMetricExporter $metrics;

    private MeterProviderInterface $meters;

    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();

        $this->spans = new InMemorySpanExporter();
        // The batch processor is the one that needs a boundary: it holds spans until the
        // batch is full or somebody flushes, and the SDK has no timer to do it.
        $this->tracers = new TracerProvider(new BatchSpanProcessor($this->spans, new FrozenClock()));

        $this->metrics = new InMemoryMetricExporter();
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->metrics))->build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->meters->shutdown();
        $this->tracers->shutdown();
        FlushPolicy::resetProcessState();
    }

    /**
     * The first message a worker handles is the one most likely to be looked at — a worker
     * that was just deployed or just recycled — and it is delivered at its own boundary
     * rather than waiting for a second message that may be minutes away.
     */
    #[Test]
    public function theFirstBoundaryDeliversBothSignals(): void
    {
        $subscriber = $this->subscriber();
        $this->record();

        $subscriber->onRunning();

        self::assertNotSame([], $this->spans->getSpans(), 'spans must not wait for the process to exit');
        self::assertNotSame([], $this->metrics->collect(), 'metrics must not wait for the process to exit');
    }

    /**
     * The idle loop dispatches WorkerRunningEvent about once a second, and every flush
     * is a blocking export: the interval is what keeps a quiet worker from hammering
     * the collector.
     */
    #[Test]
    public function anIdleLoopDoesNotExportOnEveryPoll(): void
    {
        $subscriber = $this->subscriber();
        $this->record();

        $subscriber->onRunning();
        $subscriber->onRunning();

        $exported = \count($this->spans->getSpans());

        $this->record();
        $subscriber->onRunning();
        $subscriber->onRunning();

        self::assertSame($exported, \count($this->spans->getSpans()), 'the interval has not passed yet');
    }

    /**
     * A stopping worker has no next boundary to defer to, so the interval does not
     * apply — whatever is queued is sent or lost.
     */
    #[Test]
    public function stoppingFlushesWhateverIsLeftRegardlessOfTheInterval(): void
    {
        $subscriber = $this->subscriber();
        $this->record();

        $subscriber->onStopped();

        self::assertNotSame([], $this->spans->getSpans());
        self::assertNotSame([], $this->metrics->collect());
    }

    #[Test]
    public function theSubscriberRunsBelowTheConsumerSpansOwnListeners(): void
    {
        $events = WorkerFlushSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(WorkerStoppedEvent::class, $events);
        self::assertLessThan(
            -4096,
            ($events[WorkerStoppedEvent::class] ?? self::fail('missing event registration'))[1],
        );
    }

    private function subscriber(): WorkerFlushSubscriber
    {
        $reporter = new ExportFailureReporter(new RecordingLogger());

        return new WorkerFlushSubscriber(Flushers::coordinating(
            new SignalFlusher($this->tracers, FlushPolicy::every('traces', 60_000, new FrozenClock()), $reporter),
            new SignalFlusher(
                new NoopLoggerProvider(),
                FlushPolicy::every('logs', 60_000, new FrozenClock()),
                $reporter,
            ),
            new SignalFlusher($this->meters, FlushPolicy::every('metrics', 60_000, new FrozenClock()), $reporter),
            new FlushBudget(),
            $reporter,
        ));
    }

    private function record(): void
    {
        $this->tracers->getTracer('test')->spanBuilder('probe')->startSpan()->end();
        $this->meters->getMeter('test')->createCounter('probe')->add(1);
    }
}
