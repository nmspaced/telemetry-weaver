<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BacklogLogRecordProcessor;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BacklogSpanProcessor;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ExportBacklog;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LoggerProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TracerProviderFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\CountingSpanExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanSuppression\NoopSuppressionStrategy\NoopSuppressionStrategy;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A busy worker reaches its next boundary long before the schedule delay elapses. A full batch
 * must be exported there instead of piling up until the queue drops records.
 */
#[CoversClass(ExportBacklog::class)]
#[CoversClass(BacklogSpanProcessor::class)]
#[CoversClass(BacklogLogRecordProcessor::class)]
#[CoversClass(FlushPolicy::class)]
#[CoversClass(SignalFlusher::class)]
final class QueueFillFlushTest extends TestCase
{
    private const int BATCH = 10;

    private const int CAPACITY = 40;

    private const int REQUESTS = 100;

    private const int RECORDS_PER_REQUEST = 4;

    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();
        $this->logger = new RecordingLogger();
        $_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'] = (string) self::BATCH;
        $_SERVER['OTEL_BSP_MAX_QUEUE_SIZE'] = (string) self::CAPACITY;
        $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'] = (string) self::BATCH;
        $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE'] = (string) self::CAPACITY;
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();
        unset(
            $_SERVER['OTEL_BSP_MAX_EXPORT_BATCH_SIZE'],
            $_SERVER['OTEL_BSP_MAX_QUEUE_SIZE'],
            $_SERVER['OTEL_BLRP_MAX_EXPORT_BATCH_SIZE'],
            $_SERVER['OTEL_BLRP_MAX_QUEUE_SIZE'],
        );
    }

    /**
     * @throws \Throwable
     *
     * @experimental uses the SDK's NoopSuppressionStrategy, itself marked experimental upstream
     */
    #[Test]
    public function aBusyWorkerExportsEverySpanWithinTheScheduleDelay(): void
    {
        $exporter = new CountingSpanExporter();
        [$tracers, $flusher] = $this->tracing($exporter);
        $tracer = $tracers->getTracer('worker');

        for ($request = 0; $request < self::REQUESTS; ++$request) {
            $before = $exporter->exported;

            for ($i = 0; $i < self::RECORDS_PER_REQUEST; ++$i) {
                $tracer->spanBuilder('work')->startSpan()->end();
            }

            self::assertSame($before, $exporter->exported, 'nothing is exported inside a unit of work');
            $flusher->atBoundary();
        }

        $flusher->atShutdown();

        self::assertSame(self::REQUESTS * self::RECORDS_PER_REQUEST, $exporter->exported);
        self::assertSame([], $this->logger->messages(), 'nothing was dropped, so nothing is reported');
    }

    #[Test]
    public function aBusyWorkerExportsEveryLogRecordWithinTheScheduleDelay(): void
    {
        $exporter = new InMemoryLogExporter();
        $backlog = ExportBacklog::logRecords();
        $loggers = new LoggerProviderFactory(ResourceInfoFactory::emptyResource(), $exporter)->create($backlog);
        $registry = $this->registry();
        $registry->logs($loggers, $backlog);

        $flusher = $this->flusher($registry);
        $logger = $loggers->getLogger('worker');

        for ($request = 0; $request < self::REQUESTS; ++$request) {
            for ($i = 0; $i < self::RECORDS_PER_REQUEST; ++$i) {
                $logger->emit(new LogRecord('work'));
            }

            $flusher->atBoundary();
        }

        $flusher->atShutdown();

        self::assertCount(self::REQUESTS * self::RECORDS_PER_REQUEST, $exporter->getStorage());
    }

    /**
     * @throws \Throwable
     *
     * @experimental uses the SDK's NoopSuppressionStrategy, itself marked experimental upstream
     */
    #[Test]
    public function oneUnitOverflowingTheQueueIsReportedAtTheNextBoundary(): void
    {
        $exporter = new CountingSpanExporter();
        [$tracers, $flusher] = $this->tracing($exporter);
        $tracer = $tracers->getTracer('worker');

        for ($i = 0; $i < (self::CAPACITY + 5); ++$i) {
            $tracer->spanBuilder('work')->startSpan()->end();
        }

        $flusher->atBoundary();
        $flusher->atBoundary();

        self::assertSame(self::CAPACITY, $exporter->exported);
        self::assertCount(1, $this->logger->records, 'reported once, not on every boundary');
        self::assertSame('Telemetry export queue overflowed', $this->logger->messageAt(0));
        self::assertSame('traces', $this->logger->records[0]['context']['signal'] ?? null);
        self::assertSame(5, $this->logger->records[0]['context']['dropped'] ?? null);

        $tracer->spanBuilder('after')->startSpan()->end();
        $flusher->atShutdown();

        self::assertSame(self::CAPACITY + 1, self::exported($exporter), 'reporting the loss is not a failed flush');
    }

    /**
     * @throws \Throwable
     *
     * @experimental uses the SDK's NoopSuppressionStrategy, itself marked experimental upstream
     */
    #[Test]
    public function anUnsampledSpanTakesNoPlaceInTheQueue(): void
    {
        $backlog = new ExportBacklog(batchSize: 1, capacity: 1);
        $exporter = new CountingSpanExporter();
        $tracers = new TracerProviderFactory(
            ResourceInfoFactory::emptyResource(),
            new NoopMeterProvider(),
            $exporter,
            new NoopSuppressionStrategy(),
        )->create($backlog);

        $unsampled = SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', TraceFlags::DEFAULT);
        $tracers
            ->getTracer('worker')
            ->spanBuilder('unsampled')
            ->setParent(Span::wrap($unsampled)->storeInContext(Context::getRoot()))
            ->startSpan()
            ->end();

        self::assertFalse($backlog->holdsFullBatch());
        $tracers->shutdown();
    }

    /** Read through a call, so analysis does not keep an earlier assertion's narrowing. */
    private static function exported(CountingSpanExporter $exporter): int
    {
        return $exporter->exported;
    }

    /**
     * @return array{TracerProviderInterface, TelemetryFlusher}
     *
     * @throws \RuntimeException
     *
     * @experimental uses the SDK's NoopSuppressionStrategy, itself marked experimental upstream
     */
    private function tracing(CountingSpanExporter $exporter): array
    {
        $backlog = ExportBacklog::spans();
        $tracers = new TracerProviderFactory(
            ResourceInfoFactory::emptyResource(),
            new NoopMeterProvider(),
            $exporter,
            new NoopSuppressionStrategy(),
        )->create($backlog);
        $registry = $this->registry();
        $registry->traces($tracers, $backlog);

        return [$tracers, $this->flusher($registry)];
    }

    private function registry(): ProviderRegistry
    {
        return new ProviderRegistry(Flushers::openGate(), new ExportFailureReporter($this->logger));
    }

    private function flusher(ProviderRegistry $registry): TelemetryFlusher
    {
        return new TelemetryFlusher(
            $registry,
            new FlushBudget(),
            new ExportFailureReporter($this->logger),
            SymfonyRuntimeProfile::fromKernel(1, true),
        );
    }
}
