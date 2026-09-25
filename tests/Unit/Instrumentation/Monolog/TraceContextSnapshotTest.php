<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Monolog;

use Monolog\Level;
use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Monolog\TraceContextSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContextSnapshot::class)]
final class TraceContextSnapshotTest extends TestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string SPAN_ID = 'b7ad6b7169203331';

    #[Test]
    public function whatIsWrittenIsReadBack(): void
    {
        $trace = new TraceContext(self::TRACE_ID, self::SPAN_ID, 0x03);

        self::assertEquals($trace, TraceContextSnapshot::read(TraceContextSnapshot::write(self::record(), $trace)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadable(): iterable
    {
        $valid = ['trace_id' => self::TRACE_ID, 'span_id' => self::SPAN_ID, 'trace_flags' => '01'];

        yield 'no snapshot' => [[]];
        yield 'no span id' => [['span_id' => null] + $valid];
        yield 'no flags' => [['trace_flags' => null] + $valid];
        yield 'rewritten trace id' => [['trace_id' => 'not-a-trace-id'] + $valid];
        yield 'all-zero trace id' => [['trace_id' => \str_repeat('0', 32)] + $valid];
        yield 'numeric flags' => [['trace_flags' => 1] + $valid];
        yield 'one flag digit' => [['trace_flags' => '1'] + $valid];
        yield 'non-hex flags' => [['trace_flags' => 'zz'] + $valid];
        yield 'signed flags' => [['trace_flags' => '-1'] + $valid];
    }

    /** @param array<string, mixed> $extra */
    #[Test]
    #[DataProvider('unreadable')]
    public function anUnreadableSnapshotIsNoTrace(array $extra): void
    {
        self::assertNull(TraceContextSnapshot::read(self::record($extra)));
    }

    /** @param array<string, mixed> $extra */
    private static function record(array $extra = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message', extra: $extra);
    }
}
