<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Monolog;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\OtelLogHandler;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextProcessor;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextSnapshot;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelLogCorrelation;
use Nmspaced\TelemetryWeaver\Tests\Support\OtelLogHandlerTestCase;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which trace an exported log record belongs to, when emitting it is not the same moment as
 * writing it.
 *
 * The SDK resolves an unset context lazily — `Context::getCurrent()` when the record is
 * read — which equals the active span only while the handler runs inside the operation that
 * logged. `BufferHandler` and `FingersCrossedHandler` break exactly that assumption, and
 * they are ordinary Symfony logging configuration, not an exotic setup. So the record's own
 * snapshot decides instead.
 *
 * All of these use a storage of their own: the trace has to survive being read after its
 * scope is gone, and holding the process storage would hide whether that works.
 */
#[CoversClass(OtelLogHandler::class)]
#[CoversClass(TraceContextProcessor::class)]
#[CoversClass(TraceContextSnapshot::class)]
#[CoversClass(OtelLogCorrelation::class)]
final class OtelLogHandlerCorrelationTest extends OtelLogHandlerTestCase
{
    private TracerProvider $tracers;

    private ContextStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tracers = new TracerProvider();
        $this->storage = new ContextStorage();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tracers->shutdown();
        parent::tearDown();
    }

    /**
     * The case the correlation used to be lost in: the record is made inside the operation,
     * held by the buffer, and delivered once the operation has ended.
     */
    #[Test]
    public function aBufferedRecordKeepsTheTraceItWasCreatedIn(): void
    {
        $buffer = new BufferHandler($this->handler(correlated: true));
        $logger = $this->logger($buffer);

        $traceId = $this->inSpan('origin', static function () use ($logger): void {
            $logger->info('buffered inside the operation');
        });

        self::assertSame([], $this->records(), 'the buffer has not flushed yet');

        $buffer->flush();

        self::assertSame($traceId, $this->exportedTraceId());
    }

    /**
     * The other direction, and the one a fallback to "read the current context" gets wrong:
     * a line logged outside any trace, flushed while an unrelated operation happens to be
     * running, must not be filed under that operation.
     */
    #[Test]
    public function aRecordMadeOutsideATraceIsNotAdoptedByTheFlush(): void
    {
        $buffer = new BufferHandler($this->handler(correlated: true));
        $logger = $this->logger($buffer);

        $logger->info('logged outside any operation');

        $this->inSpan('unrelated', static function () use ($buffer): void {
            $buffer->flush();
        });

        self::assertNull(
            $this->exportedTraceId(),
            'no trace at creation must stay no trace, not the trace the flush ran inside',
        );
    }

    /**
     * Records that never left their operation keep working the same way, whether or not the
     * snapshot is there — this is the ordinary, unbuffered stack.
     */
    #[Test]
    public function anUnbufferedRecordIsCorrelatedWithTheOperationItWasLoggedIn(): void
    {
        $logger = $this->logger($this->handler(correlated: true));

        $traceId = $this->inSpan('origin', static function () use ($logger): void {
            $logger->info('written straight through');
        });

        self::assertSame($traceId, $this->exportedTraceId());
    }

    /**
     * With correlation switched off there is no snapshot to read, and the handler leaves the
     * SDK's own resolution alone rather than declaring every record untraced. That keeps
     * `logs.correlation: false` meaning what it always meant: the fields other handlers see
     * are gone, the export is not made worse.
     */
    #[Test]
    public function withoutTheProcessorTheSdkResolutionIsLeftInPlace(): void
    {
        $logger = new Logger('app', [$this->handler()]);

        // The process storage, because that fallback is `Context::getCurrent()` inside the
        // SDK: this test is about the SDK's own resolution still being reachable.
        $traceId = $this->inSpan(
            'origin',
            static function () use ($logger): void {
                $logger->info('no snapshot on this record');
            },
            Context::storage(),
        );

        self::assertSame($traceId, $this->exportedTraceId());
    }

    /**
     * A record whose correlation fields were rewritten by some other processor is not a
     * trace. Exporting it with none is honest; deriving an id from it is not.
     */
    #[Test]
    public function anUnreadableSnapshotIsNoTraceRatherThanAGuess(): void
    {
        // After the snapshot processor, not before it: Monolog applies processors in order,
        // so this one is what the handler ends up reading.
        $logger = $this->logger($this->handler(correlated: true), static function (LogRecord $record): LogRecord {
            $record->extra['trace_id'] = 'not-a-trace-id';

            return $record;
        });

        $this->inSpan('origin', static function () use ($logger): void {
            $logger->info('rewritten correlation');
        });

        self::assertNull($this->exportedTraceId());
    }

    /**
     * @param \Closure(LogRecord): LogRecord ...$processors applied after the snapshot
     */
    private function logger(HandlerInterface $handler, \Closure ...$processors): Logger
    {
        return new Logger(
            'app',
            [$handler],
            [
                new TraceContextProcessor(new OtelActiveTrace($this->storage)),
                ...$processors,
            ],
        );
    }

    /**
     * Runs the callback inside a started, activated span and answers with its trace id. The
     * scope is closed and the span ended before returning, so anything read afterwards is
     * read after the operation is over — which is the whole point here.
     *
     * @param non-empty-string $name
     */
    private function inSpan(string $name, \Closure $callback, ?ContextStorageInterface $storage = null): string
    {
        $storage ??= $this->storage;
        $span = $this->tracers->getTracer('test')->spanBuilder($name)->startSpan();
        $scope = $storage->attach($span->storeInContext(Context::getRoot()));

        try {
            $callback();
        } finally {
            $scope->detach();
            $span->end();
        }

        return $span->getContext()->getTraceId();
    }

    /** The trace id the exported record carries, or null when it carries none. */
    private function exportedTraceId(): ?string
    {
        $context = $this->record()->getSpanContext();

        return $context !== null && $context->isValid() ? $context->getTraceId() : null;
    }
}
