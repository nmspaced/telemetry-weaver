<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

/**
 * Preserve query structure without exporting potentially sensitive values.
 *
 * Everything after each `=` goes, so a value never escapes regardless of the
 * parameter's name — an allow-list of "safe" names would leak the first time
 * someone adds a parameter nobody classified. Parameter names survive, since
 * they are what makes the attribute worth recording at all.
 */
final readonly class QueryStringRedactor
{
    public static function redact(string $query): string
    {
        return \preg_replace('/=[^&;]*/', '=REDACTED', $query) ?? '';
    }
}
