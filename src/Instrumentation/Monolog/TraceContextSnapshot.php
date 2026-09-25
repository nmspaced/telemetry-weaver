<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\LogRecord;
use Nmspaced\TelemetryWeaver\Api\TraceContext;

/**
 * The trace a log record was written in, as the record itself carries it.
 *
 * It exists because writing a record and exporting it are two different moments. A
 * processor runs while the record is still inside its operation. A handler receives the
 * record when the stack decides to write it, and behind a buffer that is after the operation
 * has ended. Only the processor can see the trace, so it writes these fields, and the export
 * handler reads them instead of the context that happens to be current.
 *
 * Both directions live here so the field names and their format are defined in one place.
 *
 * @internal
 */
final readonly class TraceContextSnapshot
{
    /**
     * The `extra` fields, in the format log correlation expects everywhere: ids as lowercase
     * hex, flags as two hex digits. The export handler also keeps them out of the record's
     * attributes, because an OTLP record carries all three natively.
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

    /**
     * The trace the record was written in, or null when it was written outside any.
     *
     * An unreadable snapshot also counts as none. The processor only writes these fields
     * from a valid trace, so anything else was rewritten by another processor, and a
     * guessed trace id is worse than no trace id.
     */
    public static function read(LogRecord $record): ?TraceContext
    {
        // The common case, a record written outside any trace, is decided without an exception.
        if (!\array_key_exists(self::TRACE_ID, $record->extra)) {
            return null;
        }

        // `TraceContext` is the one place the format is validated.
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

    /** The field as written, or '' when it is missing or not a string; '' is never valid. */
    private static function field(LogRecord $record, string $key): string
    {
        return \is_string($record->extra[$key] ?? null) ? $record->extra[$key] : '';
    }

    /** The flags byte from its two hex digits, or -1 when unreadable; -1 is never valid. */
    private static function flags(LogRecord $record): int
    {
        $hex = self::field($record, self::TRACE_FLAGS);

        return \strlen($hex) === 2 && \ctype_xdigit($hex) ? (int) \hexdec($hex) : -1;
    }
}
