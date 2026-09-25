<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * Extracts operations and targets from lexed code, where literals and comments are already
 * gone. Every token is a keyword from a closed list or the name right after one.
 *
 * @internal
 */
final readonly class SqlScanner
{
    /** A quoted identifier: ANSI, MySQL or SQL Server quoting. */
    private const string QUOTED = '"(?:[^"]|"")*+"|`(?:[^`]|``)*+`|\[(?:[^\]]|\]\])*+\]';

    /** A quoted or bare name. */
    private const string NAME = '(?:' . self::QUOTED . '|[a-z_\x80-\xff][a-z0-9_$\x80-\xff]*+)';

    /** Keywords that may follow a target and are never a target or an alias. */
    private const string CLAUSE = '(?:WHERE|JOIN|INNER|LEFT|RIGHT|FULL|CROSS|OUTER|NATURAL|LATERAL|ON|USING|GROUP|ORDER|HAVING|LIMIT|OFFSET|FETCH|UNION|EXCEPT|INTERSECT|SET|VALUES|VALUE|SELECT|DEFAULT|RETURNING|FOR|WINDOW|AS|WITH|FROM|INTO|UPDATE|DELETE|INSERT|IF|ONLY|IGNORE|PARTITION|STRAIGHT_JOIN|OUTPUT)\b';

    /** Skipped: quoted names outside targets, `FROM` inside functions, upsert and row-lock `UPDATE`. */
    private const string SKIP =
        '(?:'
            . self::QUOTED
            . '|\b(?:EXTRACT|TRIM|SUBSTRING|POSITION|OVERLAY)\s*+\([^()]*+\)'
            . '|\b(?:DO|KEY|FOR)\s++UPDATE\b'
            . ')(*SKIP)(*FAIL)';

    /** A possibly schema-qualified name. */
    private const string QUALIFIED = self::NAME . '(?:\s*+\.\s*+' . self::NAME . ')*+';

    /** An optional alias, skipped. */
    private const string ALIAS = '(?:\s++(?:AS\s++)?(?!' . self::CLAUSE . ')' . self::NAME . ')?';

    /** Comma-separated targets after a target keyword. */
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

    /** The object of a schema statement (`TABLE`, `INDEX`, ...), kept in the operation. */
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

    /** Group 1 is the name; the alias is skipped. */
    private const string TARGETS = '~(' . self::QUALIFIED . ')' . self::ALIAS . '~i';

    /**
     * @param string $code the output of {@see SqlLexer::code()}
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
     * `CREATE TABLE` as written, or the verb alone.
     *
     * @param array<array-key, string|null> $match
     */
    private static function schemaOperation(array $match): string
    {
        $verb = $match['schemaOperation'] ?? '';
        $object = $match['object'] ?? '';

        if ($verb === '' || $object === '') {
            return $verb;
        }

        return \sprintf('%s %s', $verb, $object);
    }

    /**
     * The names in `a, b AS x, "c" y`, without aliases.
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
