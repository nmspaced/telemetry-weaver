<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * `db.query.summary` for a SQL statement: its operations and targets, in order.
 *
 * The database conventions name a span after this summary and say explicitly that
 * `db.operation.name` and `db.collection.name` should *not* be extracted from the query
 * text — those are for instrumentation that is told them by an API. A driver only ever
 * sees the string, so the summary is the one honest thing to derive from it, in the
 * format the conventions give: `{operation} {target} {operation} {target} ...`, original
 * case and order, at most 255 characters, never cut inside a token.
 *
 * The words come from `SqlScanner`, which refuses to read data: nothing that is a literal,
 * a comment or a function argument can become part of a summary. This class decides
 * whether a statement is one worth describing at all, and enforces the length cap.
 *
 * The summary is a span attribute and the span name, not a metric label. Its cardinality
 * is bounded by the statements an application's code contains, which is fine for traces;
 * for a histogram, one sharded or dynamically named table is enough to make the label
 * set unbounded, so `DoctrineTelemetry` keeps it out of `db.client.operation.duration`.
 *
 * Deliberately not memoised. A `SQL => summary` cache in a process that serves
 * thousands of requests grows with every distinct statement, and the statements that
 * would benefit least from it (one-off migrations, admin queries) are exactly the ones
 * that would stay in it forever.
 *
 * An unrecognised leading statement yields null rather than a guess — `EXPLAIN SELECT`
 * is not a SELECT, and the span falls back to the system name.
 */
final readonly class SqlSummary
{
    /** The conventions' cap for a summary built by parsing. */
    private const int MAX_LENGTH = 255;

    /**
     * Statements this summary describes. A closed list, not a "first word" heuristic.
     *
     * @var list<non-empty-string>
     */
    private const array STATEMENTS = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'MERGE',
        'REPLACE',
        'WITH',
        'CREATE',
        'ALTER',
        'DROP',
        'TRUNCATE',
        'CALL',
        'SAVEPOINT',
        'RELEASE',
    ];

    /**
     * Skips leading whitespace, line comments and block comments, then captures the
     * first word. Doctrine and the ORM both emit commented SQL, and a migration file
     * routinely starts with one.
     */
    private const string LEADING = '~^(?:\s++|--[^\n]*+|/\*.*?\*/)*+([a-z]++)~is';

    /**
     * @param non-empty-string|null $value
     */
    private function __construct(
        public ?string $value,
    ) {}

    public static function of(string $sql): self
    {
        if (!self::isDescribable($sql)) {
            return new self(null);
        }

        $tokens = [];
        $length = -1;

        foreach (SqlScanner::tokens($sql) as $token) {
            $length += \strlen($token) + 1;

            if ($length > self::MAX_LENGTH) {
                break;
            }

            $tokens[] = $token;
        }

        return new self($tokens === [] ? null : \implode(' ', $tokens));
    }

    private static function isDescribable(string $sql): bool
    {
        $matches = [];

        if (\preg_match(self::LEADING, $sql, $matches) !== 1) {
            return false;
        }

        /** @var array{non-empty-string, non-empty-string} $matches */
        return \in_array(\strtoupper($matches[1]), self::STATEMENTS, true);
    }
}
