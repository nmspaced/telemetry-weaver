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
 *
 * `&` is the only separator, and that is a correctness decision rather than a
 * simplification. Treating `;` as one too — PHP's `arg_separator.input` has
 * defaulted to `&` alone for a decade, and the URL standard never allowed the
 * other — ended the redaction early: `token=abc;private-secret` came out as
 * `token=REDACTED;private-secret` while `parse_str()` read the whole thing,
 * semicolon included, as the token's value. Half a secret in a backend is a
 * leak. A separator the parser does not honour must stay part of the value.
 */
final readonly class QueryStringRedactor
{
    public static function redact(string $query): string
    {
        return \preg_replace('/=[^&]*/', '=REDACTED', $query) ?? '';
    }
}
