<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

/**
 * Keeps query parameter names and redacts every value. A segment without `=` is redacted
 * whole: a bare token is as likely a secret as a flag name.
 *
 * Only `&` separates parameters: a `;` the parser does not honour stays part of the value, so
 * redaction cannot end early.
 */
final readonly class QueryStringRedactor
{
    private const string VALUE = '/=[^&]*+/';

    private const string BARE_SEGMENT = '/(?<=^|&)[^&=]++(?=&|$)/';

    public static function redact(string $query): string
    {
        return \preg_replace([self::VALUE, self::BARE_SEGMENT], ['=REDACTED', 'REDACTED'], $query) ?? '';
    }
}
