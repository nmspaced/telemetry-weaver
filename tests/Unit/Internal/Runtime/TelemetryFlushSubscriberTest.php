<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle\TelemetryFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(TelemetryFlushSubscriber::class)]
final class TelemetryFlushSubscriberTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();
    }

    #[Test]
    public function itFlushesAfterTheTelemetrySubscriberHasClosedTheSpan(): void
    {
        $flush = TelemetryFlushSubscriber::getSubscribedEvents()[KernelEvents::TERMINATE] ?? [];
        $telemetry = HttpServerTracingSubscriber::getSubscribedEvents()[KernelEvents::TERMINATE] ?? [];

        self::assertNotSame([], $flush);
        self::assertNotSame([], $telemetry);

        $flushPriority = \min(\array_column($flush, 1));
        $telemetryPriority = \min(\array_column($telemetry, 1));

        self::assertLessThan($telemetryPriority, $flushPriority);
    }

    #[Test]
    public function terminateReachesTheFlush(): void
    {
        $exporter = new InMemoryExporter();
        $provider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();
        $provider->getMeter('test')->createCounter('probe')->add(1);

        $reporter = new ExportFailureReporter(new RecordingLogger());
        $tracers = new TracerProvider(new SimpleSpanProcessor(new InMemorySpanExporter()));

        $subscriber = new TelemetryFlushSubscriber(
            Flushers::coordinating(
                new SignalFlusher($tracers, FlushPolicy::every('traces', 60_000, new FrozenClock()), $reporter),
                new SignalFlusher(
                    new NoopLoggerProvider(),
                    FlushPolicy::every('logs', 60_000, new FrozenClock()),
                    $reporter,
                ),
                new SignalFlusher($provider, FlushPolicy::every('metrics', 60_000, new FrozenClock()), $reporter),
                new FlushBudget(),
                $reporter,
            ),
            SymfonyRuntimeProfile::fromKernel(1, true),
        );

        $subscriber->onTerminate();
        $subscriber->onTerminate();

        self::assertNotSame([], $exporter->collect(true));

        $provider->shutdown();
        $tracers->shutdown();
    }
}
