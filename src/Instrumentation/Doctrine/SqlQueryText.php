<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * Sanitized `db.query.text`: builds on {@see SqlLexer::code()} and also replaces numeric
 * literals with `?`. Repeated placeholder tuples of a bulk insert collapse into one, so the
 * text stays the size of the statement's shape rather than its data.
 *
 * @internal
 */
final readonly class SqlQueryText
{
    /** Numeric literals with an optional sign, outside quoted names and names with digits. */
    private const string NUMBERS = <<<'REGEX'
        ~(?:"(?:[^"]|"")*+"|`(?:[^`]|``)*+`|\[[^]]*+])(*SKIP)(*FAIL)
        |[+-]?(?<![\w$.])(?:0x[0-9a-f]++|(?:\d++(?:\.\d*+)?|\.\d++)(?:e[+-]?\d++)?)(?![\w$])
        ~xi
        REGEX;

    /** Two or more identical placeholder tuples in a row, separated by commas. */
    private const string REPEATED_TUPLES = '~(\(\s*+\?(?:\s*+,\s*+\?)*+\s*+\))(?:\s*+,\s*+\1)++~';

    private function __construct() {}

    /**
     * @param string|null $code a statement as {@see SqlLexer::code()} returned it
     *
     * @return non-empty-string|null null when the lexer refused the statement
     */
    public static function sanitized(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $text = \preg_replace([self::NUMBERS, self::REPEATED_TUPLES], ['?', '$1'], $code);

        return $text === null || $text === '' || \trim($text) === '' ? null : $text;
    }
}
