<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;

/**
 * Removes a statement's data and keeps its code, or refuses the statement.
 *
 * It reads only a small subset of SQL, and every system reads that subset the same way:
 *
 *  - `'...'` literals with quote doubling and no backslash;
 *  - quoted identifiers: `` `...` ``, `"..."` with no backslash, and `[...]` with no quote
 *    inside;
 *  - `--` comments followed by whitespace or the end of the line;
 *  - `/* *&#47;` comments with no `/*` inside.
 *
 * Each literal becomes `?` and each comment a space. Quoted identifiers stay as written,
 * except where `"..."` may be a string (see {@see self::DOUBLE_QUOTED_STRINGS}).
 *
 * The result is lexed once per statement and serves both {@see SqlSummary} and
 * {@see SqlQueryText}.
 *
 * Anything else that could start a literal or a comment refuses the whole statement. That
 * covers a backslash in a quoted run, `#`, `$$` and `$tag$`, Oracle's `q'`, nested
 * comments, `--` without whitespace, and anything unterminated. Each of those means
 * different things to different systems, or to the same system under different session
 * settings, and describing a statement from a wrong guess is how a literal reaches a span
 * name. A refused statement costs only a less specific span name. The SQL that the ORM and
 * DBAL generate stays inside the subset: values are bound as parameters, and there are no
 * comments.
 *
 * One pass of one constant pattern, possessive throughout, so it cannot backtrack. PCRE's
 * own cache compiles it once per process.
 *
 * @internal
 */
final readonly class SqlLexer
{
    /**
     * Earlier alternatives win at the same offset, so `unsafe` only matches what the safe
     * forms before it could not read.
     *
     * `#` is refused wherever it stands. MySQL starts a comment at it with or without
     * whitespace in front (`SELECT 1#...`), and no position makes it safe to read as code.
     * `$tag$` and `q'` are refused unless a letter, `_` or `$` in front makes them part of a
     * name. A digit does not: `1$$...$$` and `1q'...'` are a number followed by a string.
     */
    private const string RUNS = <<<'REGEX'
        ~(?<literal>'(?:[^'\\]++|'')*+')
        |(?<doubleQuoted>"(?:[^"\\]++|"")*+")
        |(?<identifier>`(?:[^`]++|``)*+`|\[[^]'"`]*+])
        |(?<comment>--(?:[\t\x20][^\r\n]*+)?(?=\r?\n|\z)|/\*(?:[^*/]++|\*(?!/)|/(?!\*))*+\*/)
        |(?<unsafe>['"`[\\\#]|--|/\*|(?<![a-z_$\x80-\xff])(?:\$\w*+\$|n?q'))
        ~xi
        REGEX;

    /**
     * Written in place of a run that is refused, and looked for afterwards. A statement that
     * already contains one is refused before anything is replaced, so it is unambiguous.
     */
    private const string REFUSED = "\0";

    /**
     * Systems where `"..."` may be a string literal rather than a quoted identifier: MySQL
     * and MariaDB unless the session runs with `ANSI_QUOTES`, and any system the connection
     * does not identify. Such a run is replaced like any other literal.
     *
     * @var list<non-empty-string>
     */
    private const array DOUBLE_QUOTED_STRINGS = [
        DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MYSQL,
        DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_MARIADB,
        DbIncubatingAttributes::DB_SYSTEM_NAME_VALUE_OTHER_SQL,
    ];

    private function __construct() {}

    /**
     * @param string $system the connection's `db.system.name`
     */
    public static function code(string $sql, string $system): ?string
    {
        $doubleQuotesMayBeData = \in_array($system, self::DOUBLE_QUOTED_STRINGS, true);
        if (\str_contains($sql, self::REFUSED)) {
            return null;
        }

        $code = \preg_replace_callback(
            self::RUNS,
            /** @param array<array-key, string> $run */
            static fn(array $run): string => self::replacement($run, $doubleQuotesMayBeData),
            $sql,
        );

        return $code === null || \str_contains($code, self::REFUSED) ? null : $code;
    }

    /**
     * @param array<array-key, string> $run
     */
    private static function replacement(array $run, bool $doubleQuotesMayBeData): string
    {
        return match (true) {
            ($run['unsafe'] ?? '') !== '' => self::REFUSED,
            ($run['comment'] ?? '') !== '' => ' ',
            ($run['literal'] ?? '') !== '', $doubleQuotesMayBeData && ($run['doubleQuoted'] ?? '') !== '' => '?',
            default => $run[0] ?? '',
        };
    }
}
