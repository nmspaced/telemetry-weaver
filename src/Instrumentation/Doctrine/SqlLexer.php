<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;

/**
 * Replaces literals with `?` and comments with a space, or refuses the statement (null).
 *
 * It reads only syntax every database interprets the same way: `'...'` literals without
 * backslashes, quoted names, `-- ` and non-nested `/* *&#47;` comments. Anything else that could
 * start a literal or comment (`#`, `$$`, `q'`, a backslash, nested comments) refuses the whole
 * statement rather than guessing.
 *
 * @internal
 */
final readonly class SqlLexer
{
    /**
     * Safe runs first; `unsafe` matches what they could not read. `#` is always unsafe;
     * `$tag$` and `q'` are unsafe unless they are part of a name.
     */
    private const string RUNS = <<<'REGEX'
        ~(?<literal>'(?:[^'\\]++|'')*+')
        |(?<doubleQuoted>"(?:[^"\\]++|"")*+")
        |(?<identifier>`(?:[^`]++|``)*+`|\[[^]'"`]*+])
        |(?<comment>--(?:[\t\x20][^\r\n]*+)?(?=\r?\n|\z)|/\*(?:[^*/]++|\*(?!/)|/(?!\*))*+\*/)
        |(?<unsafe>['"`[\\\#]|--|/\*|(?<![a-z_$\x80-\xff])(?:\$\w*+\$|n?q'))
        ~xi
        REGEX;

    /** Marks a refused run; a statement that already contains it is refused up front. */
    private const string REFUSED = "\0";

    /**
     * Systems where `"..."` may be a string literal, so it is replaced like one.
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
