<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Monolog;

use Monolog\Level;
use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `logs.correlation` is a promise about what a log line carries, and this is the only thing
 * that keeps it. The field names are part of that promise: a backend that joins traces to
 * logs is configured against them, so renaming one is a breaking change dressed as a refactor.
 */
#[CoversClass(TraceContextProcessor::class)]
final class TraceContextProcessorTest extends TestCase
{
    #[Test]
    public function theRunningTraceIsStampedOnTheRecord(): void
    {
        $processor = new TraceContextProcessor($this->reading(new TraceContext(
            '0af7651916cd43dd8448eb211c80319c',
            'b7ad6b7169203331',
            1,
        )));

        $extra = $processor($this->record())->extra;

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $extra['trace_id'] ?? null);
        self::assertSame('b7ad6b7169203331', $extra['span_id'] ?? null);
    }

    /**
     * Two hex digits, as the W3C header spells them — `1` and `01` are the same number and
     * only one of them joins up with a `traceparent` a backend already stored.
     */
    #[Test]
    public function theFlagsAreTwoHexDigits(): void
    {
        $sampled = new TraceContextProcessor($this->tracing(traceFlags: 1));
        $notSampled = new TraceContextProcessor($this->tracing(traceFlags: 0));

        self::assertSame('01', $sampled($this->record())->extra['trace_flags'] ?? null);
        self::assertSame('00', $notSampled($this->record())->extra['trace_flags'] ?? null);
    }

    /**
     * A line written outside any trace is not half-correlated: absent ids and empty ids both
     * cost a query on the backend, and an empty one also looks like a real value.
     */
    #[Test]
    public function aRecordOutsideATraceIsUntouched(): void
    {
        $processor = new TraceContextProcessor($this->reading(null));

        $record = $this->record(['app.order' => 7]);
        $result = $processor($record);

        self::assertSame(['app.order' => 7], $result->extra);
    }

    /**
     * Monolog processors compose, and one that dropped what an earlier one added would make
     * the order they were registered in load-bearing.
     */
    #[Test]
    public function whatAnEarlierProcessorAddedSurvives(): void
    {
        $processor = new TraceContextProcessor($this->tracing());

        $extra = $processor($this->record(['app.order' => 7]))->extra;

        self::assertSame(7, $extra['app.order'] ?? null);
        self::assertArrayHasKey('trace_id', $extra);
    }

    /**
     * @param int<0, 255> $traceFlags
     */
    private function tracing(int $traceFlags = 1): ActiveTrace
    {
        return $this->reading(new TraceContext(\str_repeat('a', 32), \str_repeat('b', 16), $traceFlags));
    }

    private function reading(?TraceContext $current): ActiveTrace
    {
        return new readonly class($current) implements ActiveTrace {
            public function __construct(
                private ?TraceContext $current,
            ) {}

            #[\Override]
            public function current(): ?TraceContext
            {
                return $this->current;
            }
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function record(array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message', [], $extra);
    }
}
