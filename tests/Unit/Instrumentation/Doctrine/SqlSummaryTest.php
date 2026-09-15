<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Doctrine;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSummary::class)]
final class SqlSummaryTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function statements(): iterable
    {
        yield 'select' => ['SELECT id FROM users WHERE id = ?', 'SELECT users'];
        yield 'insert' => ['INSERT INTO users (id) VALUES (?)', 'INSERT users'];
        yield 'update' => ['UPDATE users SET name = ? WHERE id = ?', 'UPDATE users'];
        yield 'delete' => ['DELETE FROM users WHERE id = ?', 'DELETE users'];

        // The conventions keep the case the statement was written in.
        yield 'lowercase' => ['select id from users', 'select users'];

        yield 'multiline' => ["SELECT\n  id,\n  name\nFROM\n  users\n", 'SELECT users'];
        yield 'leading line comment' => ["-- fixture reset\nDELETE FROM users", 'DELETE users'];
        yield 'leading block comment' => ['/* app: api */ SELECT 1 FROM users', 'SELECT users'];

        // Targets are kept as written: the summary is a span attribute, not a label.
        yield 'backticks' => ['SELECT * FROM `users`', 'SELECT `users`'];
        yield 'quoted schema' => ['SELECT * FROM "public"."users"', 'SELECT "public"."users"'];
        yield 'bracket quoting' => ['SELECT * FROM [users]', 'SELECT [users]'];
        yield 'unquoted schema' => ['SELECT * FROM public.users', 'SELECT public.users'];

        // The examples of the database span conventions, verbatim.
        yield 'semconv: simple' => ["SELECT *\nFROM   wuser_table\nWHERE  username = ?", 'SELECT wuser_table'];
        yield 'semconv: insert select' => [
            "INSERT INTO shipping_details\n (order_id,\n address)\nSELECT order_id,\n address\nFROM orders\nWHERE order_id = ?",
            'INSERT shipping_details SELECT orders',
        ];
        yield 'semconv: two tables' => [
            'SELECT * FROM songs, artists WHERE songs.artist_id == artists.id',
            'SELECT songs artists',
        ];
        yield 'semconv: subquery' => [
            'SELECT order_date FROM (SELECT * FROM orders o JOIN customers c ON o.customer_id = c.customer_id)',
            'SELECT SELECT orders customers',
        ];
        yield 'semconv: quoted names' => ['SELECT * FROM "song list", \'artists\'', 'SELECT "song list" \'artists\''];

        yield 'aliases' => ['SELECT * FROM orders AS o LEFT JOIN users u ON u.id = o.user_id', 'SELECT orders users'];
        yield 'cte' => ['WITH recent AS (SELECT * FROM orders) SELECT * FROM recent', 'SELECT orders SELECT recent'];
        yield 'postgres upsert' => [
            'INSERT INTO users (id) VALUES (?) ON CONFLICT (id) DO UPDATE SET name = ?',
            'INSERT users',
        ];
        yield 'mysql upsert' => [
            'INSERT INTO users (id) VALUES (?) ON DUPLICATE KEY UPDATE name = ?',
            'INSERT users',
        ];
        yield 'row lock' => ['SELECT * FROM jobs WHERE id = ? FOR UPDATE', 'SELECT jobs'];
        yield 'ddl' => ['TRUNCATE TABLE users', 'TRUNCATE users'];
        yield 'no table' => ['SELECT 1', 'SELECT'];
        yield 'call' => ['CALL refresh_totals(?)', 'CALL refresh_totals'];

        yield 'unknown token' => ['EXPLAIN SELECT * FROM users', null];
        yield 'pragma' => ['PRAGMA foreign_keys = ON', null];
        yield 'empty' => ['', null];
        yield 'whitespace only' => ["  \n\t", null];
    }

    #[Test]
    #[DataProvider('statements')]
    public function theSummaryListsOperationsAndTargetsInOrder(string $sql, ?string $summary): void
    {
        self::assertSame($summary, SqlSummary::of($sql)->value);
    }

    /**
     * Nothing that is data may become part of the summary: a table name read out of a
     * string literal or a comment is at best wrong and at worst a user's input.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function lookalikes(): iterable
    {
        yield 'string literal' => ["SELECT 'select * from secrets' FROM users", 'SELECT users'];
        yield 'escaped quote' => ["SELECT 'it''s from secrets' FROM users", 'SELECT users'];
        yield 'backslash escape' => ["SELECT 'it\\'s from secrets' FROM users", 'SELECT users'];
        yield 'inner line comment' => ["SELECT id -- from secrets\nFROM users", 'SELECT users'];
        yield 'inner block comment' => ['SELECT id /* FROM secrets */ FROM users', 'SELECT users'];
        yield 'dollar quoting' => ['SELECT $body$ from secrets $body$ FROM users', 'SELECT users'];
        yield 'extract' => ['SELECT EXTRACT(YEAR FROM created_at) FROM orders', 'SELECT orders'];
        yield 'trim' => ["SELECT TRIM(BOTH ' ' FROM name) FROM users", 'SELECT users'];
        yield 'substring' => ['SELECT SUBSTRING(name FROM 2) FROM users', 'SELECT users'];
        yield 'column named like a keyword' => ['SELECT from_date, into_account FROM ledger', 'SELECT ledger'];
    }

    #[Test]
    #[DataProvider('lookalikes')]
    public function dataIsNeverReadAsATarget(string $sql, string $summary): void
    {
        self::assertSame($summary, SqlSummary::of($sql)->value);
    }

    /**
     * The conventions cap a parsed summary at 255 characters and forbid cutting an
     * operation or a target in half — a truncated name would group unrelated queries.
     */
    #[Test]
    public function aLongSummaryIsTruncatedBetweenTokens(): void
    {
        $tables = \array_map(static fn(int $i): string => \sprintf('table_%03d', $i), \range(1, 60));
        $summary = SqlSummary::of('SELECT * FROM ' . \implode(', ', $tables))->value;

        self::assertNotNull($summary);
        self::assertLessThanOrEqual(255, \strlen($summary));
        self::assertStringStartsWith('SELECT table_001 table_002', $summary);
        self::assertMatchesRegularExpression('~ table_\d{3}$~', $summary, 'the last token is whole');
    }

    /**
     * An unrecognised leading statement yields no summary at all rather than whatever
     * tokens happen to follow it: `EXPLAIN SELECT ...` is not a SELECT.
     */
    #[Test]
    public function anUnrecognisedStatementHasNoSummary(): void
    {
        self::assertNull(SqlSummary::of('EXPLAIN ANALYZE SELECT * FROM users')->value);
    }

    /**
     * A bulk insert is the longest statement an application sends. Its values are data,
     * and none of them may reach the summary, however many there are.
     */
    #[Test]
    public function aBulkInsertSummarisesToItsTarget(): void
    {
        $rows = \implode(', ', \array_fill(0, 5000, "(1, 'from x', 'into y')"));

        self::assertSame('INSERT events', SqlSummary::of('INSERT INTO events (a, b, c) VALUES ' . $rows)->value);
    }
}
