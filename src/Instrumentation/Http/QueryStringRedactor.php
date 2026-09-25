<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

/**
 * Keeps query parameter names and redacts every value.
 *
 * Only `&` separates parameters: a `;` the parser does not honour stays part of the value, so
 * redaction cannot end early.
 */
final readonly class QueryStringRedactor
{
    public static function redact(string $query): string
    {
        return \preg_replace('/=[^&]*/', '=REDACTED', $query) ?? '';
    }
}
