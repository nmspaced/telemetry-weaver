<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * The operations and targets of a statement, in order — and nothing else.
 *
 * It reads the output of {@see SqlLexer}, never raw SQL: literals are already `?` and
 * comments already whitespace, so there is no data left for it to misread, and nothing
 * here depends on the dialect. What remains to be skipped is code that looks like a
 * target without being one — keywords inside a quoted identifier, `FROM` inside the
 * syntax of a function (`EXTRACT(YEAR FROM x)`), and the `UPDATE` of an upsert or a row
 * lock. Every word it returns is therefore either a keyword from a closed list or the
 * name immediately after one.
 *
 * One `preg_match_all` over the statement, with patterns that are constant strings and so
 * compiled once per process by PCRE's own cache.
 *
 * @internal
 */
final readonly class SqlScanner
{
    /** A quoted identifier as the lexer kept it: ANSI, MySQL or SQL Server quoting. */
    private const string QUOTED = '"(?:[^"]|"")*+"|`(?:[^`]|``)*+`|\[(?:[^\]]|\]\])*+\]';

    /** One name as it may be written: quoted, or bare. */
    private const string NAME = '(?:' . self::QUOTED . '|[a-z_\x80-\xff][a-z0-9_$\x80-\xff]*+)';

    /**
     * Words that follow a target without being one — the start of the next clause, or a
     * modifier. An alias check needs them too: `FROM a JOIN b` must not read JOIN as a's
     * alias and so lose b.
     */
    private const string CLAUSE = '(?:WHERE|JOIN|INNER|LEFT|RIGHT|FULL|CROSS|OUTER|NATURAL|LATERAL|ON|USING|GROUP|ORDER|HAVING|LIMIT|OFFSET|FETCH|UNION|EXCEPT|INTERSECT|SET|VALUES|VALUE|SELECT|DEFAULT|RETURNING|FOR|WINDOW|AS|WITH|FROM|INTO|UPDATE|DELETE|INSERT|IF|ONLY|IGNORE|PARTITION|STRAIGHT_JOIN|OUTPUT)\b';

    /**
     * Consumed and discarded: quoted identifiers outside a target position, `FROM` inside
     * the syntax of a function, and the `UPDATE` of an upsert or a row lock, which is not
     * a second statement.
     */
    private const string SKIP =
        '(?:'
            . self::QUOTED
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
     * What a schema statement acts on, which the conventions keep in the operation:
     * `CREATE TABLE MyTable`, not `CREATE MyTable`. Preceded by the modifiers that may sit
     * between the verb and the object, and followed by `IF [NOT] EXISTS`.
     */
    private const string DDL_OBJECT =
        '(?:OR\s++REPLACE\s++)?(?:(?:GLOBAL|LOCAL)\s++)?(?:(?:TEMPORARY|TEMP|UNIQUE|MATERIALIZED|UNLOGGED)\s++)?'
            . '(?<object>TABLE|VIEW|INDEX|SEQUENCE|SCHEMA|DATABASE|FUNCTION|PROCEDURE|TRIGGER|TYPE|EXTENSION)\b'
            . '(?:\s++IF\s++(?:NOT\s++)?EXISTS\b)?';

    private const string TOKENS =
        '~'
            . self::SKIP
            . '|\b(?<operationWithTarget>UPDATE|CALL)\b(?:\s++(?<ownTargets>'
            . self::LIST
            . '))?'
            . '|\b(?<schemaOperation>CREATE|ALTER|DROP|TRUNCATE)\b(?:\s++'
            . self::DDL_OBJECT
            . '(?:\s++(?<schemaTargets>'
            . self::LIST
            . '))?)?'
            . '|\b(?<operation>SELECT|INSERT|DELETE|MERGE|REPLACE|SAVEPOINT|RELEASE)\b'
            . '|\b(?:FROM|INTO|JOIN|USING)\b(?:\s++(?<targets>'
            . self::LIST
            . '))?'
            . '~i';

    /** Group 1 is the name; the alias after it is matched only to be skipped. */
    private const string TARGETS = '~(' . self::QUALIFIED . ')' . self::ALIAS . '~i';

    /**
     * @param string $code a statement as {@see SqlLexer::code()} returned it
     *
     * @return list<non-empty-string>
     */
    public static function tokens(string $code): array
    {
        $matches = [];

        if (\preg_match_all(self::TOKENS, $code, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $tokens = [];

        foreach ($matches as $match) {
            $operation = $match['operationWithTarget'] ?? $match['operation'] ?? self::schemaOperation($match);

            if ($operation !== '') {
                $tokens[] = $operation;
            }

            foreach (self::targets(
                $match['ownTargets'] ?? $match['schemaTargets'] ?? $match['targets'] ?? '',
            ) as $target) {
                $tokens[] = $target;
            }
        }

        return $tokens;
    }

    /**
     * `CREATE TABLE`, as written; the verb alone when no object follows it.
     *
     * @param array<array-key, string|null> $match
     */
    private static function schemaOperation(array $match): string
    {
        $verb = $match['schemaOperation'] ?? '';
        $object = $match['object'] ?? '';

        return $verb === '' || $object === '' ? $verb : $verb . ' ' . $object;
    }

    /**
     * The names in `a, b AS x, "c" y`, without their aliases. Never empty: a name is at
     * least one character.
     *
     * @return list<non-empty-string>
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
