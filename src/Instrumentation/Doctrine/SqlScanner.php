<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * The operations and targets of a SQL statement, in order — and nothing else.
 *
 * It is not a parser in the sense of understanding SQL. It is a scanner that refuses to
 * read data: string literals (quote doubling, backslash escapes and Postgres dollar
 * quoting), comments, and the handful of functions whose syntax contains `FROM`
 * (`EXTRACT(YEAR FROM x)`) are consumed without producing anything. Every word it returns
 * is therefore either a keyword from a closed list or the name immediately after one —
 * never a value, and never text a user typed into a form.
 *
 * One `preg_match_all` over the statement, with patterns that are constant strings and so
 * compiled once per process by PCRE's own cache. A bulk insert costs time linear in its
 * length and produces no matches for its values.
 *
 * @internal
 */
final readonly class SqlScanner
{
    /** One name as it may be written: ANSI, MySQL, SQL Server quoting, or bare. */
    private const string NAME = '(?:"(?:[^"]|"")++"|`[^`]++`|\[[^\]]++\]|\'(?:[^\'\\\\]|\\\\.|\'\')++\'|[a-z_][a-z0-9_$]*+)';

    /**
     * Words that follow a target without being one — the start of the next clause, or a
     * modifier. An alias check needs them too: `FROM a JOIN b` must not read JOIN as a's
     * alias and so lose b.
     */
    private const string CLAUSE = '(?:WHERE|JOIN|INNER|LEFT|RIGHT|FULL|CROSS|OUTER|NATURAL|LATERAL|ON|USING|GROUP|ORDER|HAVING|LIMIT|OFFSET|FETCH|UNION|EXCEPT|INTERSECT|SET|VALUES|VALUE|SELECT|DEFAULT|RETURNING|FOR|WINDOW|AS|WITH|FROM|INTO|TABLE|UPDATE|DELETE|INSERT|IF|ONLY|IGNORE|PARTITION|STRAIGHT_JOIN|OUTPUT)\b';

    /**
     * Consumed and discarded: literals, comments, `FROM` inside the syntax of a function,
     * and the `UPDATE` of an upsert or a row lock, which is not a second statement.
     */
    private const string SKIP =
        '(?:--[^\n]*+'
            . '|/\*.*?\*/'
            . '|\'(?:[^\'\\\\]++|\\\\.|\'\')*+\''
            . '|\$(?<tag>[a-z_]\w*+|)\$.*?\$\k<tag>\$'
            . '|\b(?:EXTRACT|TRIM|SUBSTRING|POSITION|OVERLAY)\s*+\([^()]*+\)'
            . '|\b(?:DO|KEY|FOR)\s++UPDATE\b'
            . ')(*SKIP)(*FAIL)';

    /** A possibly schema-qualified name. */
    private const string QUALIFIED = self::NAME . '(?:\s*+\.\s*+' . self::NAME . ')*+';

    /** An optional alias, which is read past and never reported. */
    private const string ALIAS = '(?:\s++(?:AS\s++)?(?!' . self::CLAUSE . ')' . self::NAME . ')?';

    /** The comma-separated names after a keyword that introduces targets. */
    private const string LIST =
        '(?!'
            . self::CLAUSE
            . ')'
            . self::QUALIFIED
            . self::ALIAS
            . '(?:\s*+,\s*+(?!'
            . self::CLAUSE
            . ')'
            . self::QUALIFIED
            . self::ALIAS
            . ')*+';

    /**
     * Compiled once per process by PCRE's own cache: the pattern is a constant string, so
     * nothing is rebuilt per statement.
     */
    private const string TOKENS =
        '~'
            . self::SKIP
            . '|\b(?<operationWithTarget>UPDATE|CALL)\b(?:\s++(?<ownTargets>'
            . self::LIST
            . '))?'
            . '|\b(?<operation>SELECT|INSERT|DELETE|MERGE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|SAVEPOINT|RELEASE)\b'
            . '|\b(?:FROM|INTO|JOIN|TABLE|USING)\b(?:\s++(?<targets>'
            . self::LIST
            . '))?'
            . '~is';

    /** Group 1 is the name; the alias after it is matched only to be skipped. */
    private const string TARGETS = '~(' . self::QUALIFIED . ')' . self::ALIAS . '~is';

    /**
     * @return list<non-empty-string>
     */
    public static function tokens(string $sql): array
    {
        $matches = [];

        if (\preg_match_all(self::TOKENS, $sql, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $tokens = [];

        foreach ($matches as $match) {
            $operation = $match['operationWithTarget'] ?? $match['operation'] ?? '';

            if ($operation !== '') {
                $tokens[] = $operation;
            }

            foreach (self::targets($match['ownTargets'] ?? $match['targets'] ?? '') as $target) {
                if ($target === '') {
                    continue;
                }

                $tokens[] = $target;
            }
        }

        return $tokens;
    }

    /**
     * The names in `a, b AS x, "c" y`, without their aliases.
     *
     * @return list<string>
     */
    private static function targets(string $list): array
    {
        $matches = [];

        if ($list === '' || \preg_match_all(self::TARGETS, $list, $matches) === false) {
            return [];
        }

        /** @var array{list<string>, list<non-empty-string>} $matches */
        return $matches[1];
    }
}
