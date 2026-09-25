<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Api\TraceContext;

/**
 * Reads and writes the trace IDs a log record carries in `extra`. A processor writes them
 * while the record is still inside its operation; the export handler reads them later,
 * because behind a buffer the current context is no longer the record's.
 *
 * @internal
 */
final readonly class TraceContextSnapshot
{
    /**
     * The `extra` keys: IDs as lowercase hex, flags as two hex digits.
     *
     * @var list<non-empty-string>
     */
    public const array KEYS = [self::TRACE_ID, self::SPAN_ID, self::TRACE_FLAGS];

    private const string TRACE_ID = 'trace_id';

    private const string SPAN_ID = 'span_id';

    private const string TRACE_FLAGS = 'trace_flags';

    private function __construct() {}

    public static function write(LogRecord $record, TraceContext $trace): LogRecord
    {
        return $record->with(extra: [
            ...$record->extra,
            self::TRACE_ID => $trace->traceId,
            self::SPAN_ID => $trace->spanId,
            self::TRACE_FLAGS => $trace->traceFlagsHex(),
        ]);
    }

    /** The trace the record was written in; null when absent or unreadable. */
    public static function read(LogRecord $record): ?TraceContext
    {
        if (!\array_key_exists(self::TRACE_ID, $record->extra)) {
            return null;
        }

        try {
            return new TraceContext(
                self::field($record, self::TRACE_ID),
                self::field($record, self::SPAN_ID),
                self::flags($record),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** The field, or '' (never valid) when missing or not a string. */
    private static function field(LogRecord $record, string $key): string
    {
        return \is_string($record->extra[$key] ?? null) ? $record->extra[$key] : '';
    }

    /** The flags byte, or -1 (never valid) when unreadable. */
    private static function flags(LogRecord $record): int
    {
        $hex = self::field($record, self::TRACE_FLAGS);

        return \strlen($hex) === 2 && \ctype_xdigit($hex) ? (int) \hexdec($hex) : -1;
    }
}
