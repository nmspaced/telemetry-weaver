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

    #[Test]
    public function anUnbufferedRecordIsCorrelatedWithTheOperationItWasLoggedIn(): void
    {
        $logger = $this->logger($this->handler(correlated: true));

        $traceId = $this->inSpan('origin', static function () use ($logger): void {
            $logger->info('written straight through');
        });

        self::assertSame($traceId, $this->exportedTraceId());
    }

    #[Test]
    public function withoutTheProcessorTheSdkResolutionIsLeftInPlace(): void
    {
        $logger = new Logger('app', [$this->handler()]);

        $traceId = $this->inSpan(
            'origin',
            static function () use ($logger): void {
                $logger->info('no snapshot on this record');
            },
            Context::storage(),
        );

        self::assertSame($traceId, $this->exportedTraceId());
    }

    #[Test]
    public function anUnreadableSnapshotIsNoTraceRatherThanAGuess(): void
    {
        $logger = $this->logger($this->handler(correlated: true), static function (LogRecord $record): LogRecord {
            $record->extra['trace_id'] = 'not-a-trace-id';

            return $record;
        });

        $this->inSpan('origin', static function () use ($logger): void {
            $logger->info('rewritten correlation');
        });

        self::assertNull($this->exportedTraceId());
    }

    /** @param \Closure(LogRecord): LogRecord ...$processors applied after the snapshot */
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
     * Runs the callback inside a started, activated span and answers with its trace id.
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
