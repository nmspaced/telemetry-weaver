<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * `db.query.text` with every literal replaced by `?`, which the database conventions allow
 * to be recorded by default.
 *
 * It starts from {@see SqlLexer::code()}: string literals are already `?` and comments are
 * gone, and a statement the lexer refused has no text at all. A refused statement is one
 * whose literals cannot be found with certainty, so its text is not safe to record. What is
 * left here is the conventions' other kind of literal, numbers:
 *
 *  - `12`, `-12.5`, `1e-3`, `.5` and `0xFF` become `?`. A sign directly in front of a number
 *    is taken with it, so `99+100` becomes `??`, as in the conventions' test cases;
 *  - digits inside a name are left alone: `covid19`, `$1`, and anything quoted.
 *
 * A bulk insert repeats one tuple per row. Consecutive identical placeholder tuples are
 * collapsed into one, so `VALUES (?, ?), (?, ?), ...` is recorded as `VALUES (?, ?)`. The text
 * then stays the size of the statement's shape rather than of its data. That is also what
 * keeps the attribute from growing to megabytes now that it is on by default.
 *
 * Parameterized text is sanitized as well. The conventions would record it as written, but
 * a numeric literal next to a placeholder is still a literal, and the difference is only
 * `LIMIT 10` becoming `LIMIT ?`.
 *
 * @internal
 */
final readonly class SqlQueryText
{
    /**
     * Quoted names are skipped whole; a number is only a number where a name cannot be.
     * The lookbehind sits after the optional sign, so it checks the byte before the digits.
     */
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
