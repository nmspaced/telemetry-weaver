<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Doctrine;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlLexer;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlScanner;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSummary::class)]
#[CoversClass(SqlScanner::class)]
#[CoversClass(SqlLexer::class)]
final class SqlSummaryTest extends TestCase
{
    /** @var list<non-empty-string> the `db.system.name` values `DatabaseSystem` produces */
    private const array SYSTEMS = [
        'mysql',
        'mariadb',
        'postgresql',
        'microsoft.sql_server',
        'sqlite',
        'oracle.db',
        'ibm.db2',
        'other_sql',
    ];

    /**
     * Syntax every system reads the same way, so every case runs under all of them.
     *
     * @return iterable<string, array{string, string|null}>
     */
    private static function common(): iterable
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
        // Schema statements keep their object in the operation, as the conventions' test cases do.
        yield 'truncate' => ['TRUNCATE TABLE users', 'TRUNCATE TABLE users'];
        yield 'semconv: create table' => [
            "CREATE  TABLE MyTable (\n    ID NOT NULL IDENTITY(1,1) PRIMARY KEY\n)",
            'CREATE TABLE MyTable',
        ];
        yield 'semconv: alter table' => ['ALTER  TABLE MyTable ADD Name varchar(255)', 'ALTER TABLE MyTable'];
        yield 'semconv: drop table' => ['DROP  TABLE MyTable', 'DROP TABLE MyTable'];
        yield 'schema modifiers' => ['CREATE UNIQUE INDEX idx ON orders (id)', 'CREATE INDEX idx'];
        yield 'if not exists' => ['CREATE TABLE IF NOT EXISTS audit (id INT)', 'CREATE TABLE audit'];
        yield 'or replace' => [
            'create or replace view recent as select * from orders',
            'create view recent select orders',
        ];
        yield 'schema statement without an object' => ['DROP USER bob', 'DROP'];

        // `table` is a word, not a clause: a table may be called that.
        yield 'semconv: in clause' => ["SELECT * FROM table WHERE value IN (123, 456, 'abc')", 'SELECT table'];
        yield 'no table' => ['SELECT 1', 'SELECT'];
        yield 'call' => ['CALL refresh_totals(?)', 'CALL refresh_totals'];
        yield 'named parameter' => ['SELECT * FROM users WHERE id = :id', 'SELECT users'];

        yield 'unknown token' => ['EXPLAIN SELECT * FROM users', null];
        yield 'pragma' => ['PRAGMA foreign_keys = ON', null];
        yield 'empty' => ['', null];
        yield 'whitespace only' => ["  \n\t", null];

        // Nothing that is data may become part of the summary: a table name read out of a
        // literal or a comment is at best wrong and at worst a user's input.
        yield 'string literal' => ["SELECT 'select * from secret' FROM users", 'SELECT users'];
        yield 'doubled quote' => ["SELECT 'it''s from secret' FROM users", 'SELECT users'];
        yield 'inner line comment' => ["SELECT id -- from secret\nFROM users", 'SELECT users'];
        yield 'inner block comment' => ['SELECT id /* FROM secret */ FROM users', 'SELECT users'];
        yield 'literal in a target position' => ["SELECT * FROM 'secret'", 'SELECT'];
        yield 'extract' => ['SELECT EXTRACT(YEAR FROM created_at) FROM orders', 'SELECT orders'];
        yield 'trim' => ["SELECT TRIM(BOTH ' ' FROM name) FROM users", 'SELECT users'];
        yield 'parenthesis in a literal' => ["SELECT TRIM(BOTH ')' FROM name) FROM users", 'SELECT users'];
        yield 'substring' => ['SELECT SUBSTRING(name FROM 2) FROM users', 'SELECT users'];
        yield 'column named like a keyword' => ['SELECT from_date, into_account FROM ledger', 'SELECT ledger'];
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function commonSyntax(): iterable
    {
        foreach (self::common() as $name => [$sql, $summary]) {
            foreach (self::SYSTEMS as $system) {
                yield $name . ' / ' . $system => [$sql, $system, $summary];
            }
        }
    }

    /**
     * Quoting that the lexer reads, and where the system still matters: a MySQL `"..."`
     * may be a string, so it is treated as data. That costs a target in the summary and
     * nothing else.
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function quoting(): iterable
    {
        yield 'mysql double quotes' => ['SELECT "FROM SECRET" AS message FROM users', 'mysql', 'SELECT users'];
        yield 'mysql double-quoted target' => ['SELECT * FROM "users"', 'mysql', 'SELECT'];
        yield 'unknown system double-quoted target' => ['SELECT * FROM "users"', 'other_sql', 'SELECT'];
        yield 'postgres quoted identifier' => ['SELECT * FROM "users"', 'postgresql', 'SELECT "users"'];
        yield 'postgres quoted schema' => ['SELECT * FROM "public"."users"', 'postgresql', 'SELECT "public"."users"'];
        yield 'keyword inside a quoted identifier' => ['SELECT "from secret" FROM users', 'postgresql', 'SELECT users'];
        yield 'semconv: quoted names' => [
            'SELECT * FROM "song list", artists',
            'postgresql',
            'SELECT "song list" artists',
        ];
        yield 'backticks' => ['SELECT * FROM `users`', 'mysql', 'SELECT `users`'];
        yield 'quote inside backticks' => ["SELECT `it's` FROM users WHERE a = 'FROM SECRET'", 'mysql', 'SELECT users'];
        yield 'brackets' => ['SELECT * FROM [users]', 'microsoft.sql_server', 'SELECT [users]'];
        yield 'dash comment with a space' => ["SELECT 1 -- FROM SECRET\nFROM users", 'mysql', 'SELECT users'];
        yield 'dash comment at the end' => ['SELECT id FROM users --', 'postgresql', 'SELECT users'];
        yield 'positional parameter' => ['SELECT * FROM users WHERE id = $1', 'postgresql', 'SELECT users'];
        yield 'dollar inside a name' => ['SELECT v$session.sid FROM v$session', 'oracle.db', 'SELECT v$session'];
    }

    /**
     * Syntax that some systems, or some session settings, read differently. Guessing
     * wrong would turn data into code, so none of it is read: the span is named after the
     * system instead. `SECRET` stands for whatever the literal or comment really held.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        // `NO_BACKSLASH_ESCAPES` and `standard_conforming_strings` move a literal's end.
        yield 'backslash before a quote' => ["SELECT 'a\\' FROM SECRET -- ' FROM users", 'mysql'];
        yield 'backslash in double quotes' => ['SELECT "a\\" FROM SECRET -- " FROM users', 'mysql'];
        yield 'escape string' => ["SELECT E'it\\'s from SECRET' FROM users", 'postgresql'];
        yield 'harmless backslash' => ["SELECT 'C:\\temp' FROM users", 'sqlite'];

        // A MySQL comment, a PostgreSQL operator, a SQL Server temp table.
        yield 'hash comment' => ["SELECT 1 # FROM SECRET\nFROM users", 'mysql'];
        yield 'hash operator' => ['SELECT data #> :path FROM events', 'postgresql'];
        yield 'temp table' => ['SELECT * FROM #jobs', 'microsoft.sql_server'];
        // No position makes `#` safe: MySQL needs no whitespace in front of the comment.
        yield 'hash comment after a number' => ["SELECT 1# FROM SECRET\nFROM users", 'mysql'];
        yield 'hash comment on mariadb' => ["SELECT 1# FROM SECRET\nFROM users", 'mariadb'];
        yield 'hash inside a name' => ['SELECT a#b FROM SECRET', 'oracle.db'];

        // `1--1` is arithmetic in MySQL, and the literal after it continues onto the next line.
        yield 'dashes without a space' => ["SELECT 1--'x\nFROM SECRET' FROM users", 'mysql'];
        yield 'lone carriage return in a comment' => ["SELECT 1 -- a\rFROM SECRET", 'microsoft.sql_server'];

        // Nested in PostgreSQL and SQL Server, closed at the first `*/` elsewhere.
        yield 'nested comment' => ['SELECT 1 /* outer /* nested */ FROM SECRET */ FROM users', 'postgresql'];
        yield 'flat comment hiding a quote' => ["SELECT /* /* */ 'a */ FROM SECRET' FROM users", 'mysql'];

        // String forms one vendor adds.
        yield 'dollar quoting' => ['SELECT $body$ from SECRET $body$ FROM users', 'postgresql'];
        yield 'empty dollar tag' => ["SELECT $$ it's from SECRET $$ FROM users", 'postgresql'];
        yield 'oracle q-quote' => ["SELECT q'[it's FROM SECRET]' FROM users", 'oracle.db'];
        // A digit does not make a quote part of a name: these are a number and a string.
        yield 'q-quote after a number' => ["SELECT 1q'[it's FROM SECRET]' FROM dual", 'oracle.db'];
        yield 'dollar quote after a number' => ['SELECT 1$$ FROM SECRET $$ FROM users', 'postgresql'];
        yield 'quote inside brackets' => ["SELECT data['FROM SECRET'] FROM events", 'postgresql'];

        yield 'unterminated literal' => ["SELECT 'FROM SECRET", 'postgresql'];
        yield 'unterminated comment' => ['SELECT 1 /* FROM SECRET', 'postgresql'];
        yield 'unterminated identifier' => ['SELECT * FROM "SECRET', 'postgresql'];
    }

    #[Test]
    #[DataProvider('commonSyntax')]
    #[DataProvider('quoting')]
    public function theSummaryListsOperationsAndTargetsInOrder(string $sql, string $system, ?string $summary): void
    {
        self::assertSame($summary, SqlSummary::of($sql, $system)->value);
    }

    /**
     * Refused under every system, not only the one each case was written for: the subset
     * the lexer reads is the same everywhere.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refusedEverywhere(): iterable
    {
        foreach (self::refused() as $name => [$sql]) {
            foreach (self::SYSTEMS as $system) {
                yield $name . ' / ' . $system => [$sql, $system];
            }
        }
    }

    #[Test]
    #[DataProvider('refusedEverywhere')]
    public function syntaxOutsideTheSubsetHasNoSummary(string $sql, string $system): void
    {
        self::assertNull(SqlSummary::of($sql, $system)->value);
    }

    /**
     * The conventions cap a parsed summary at 255 characters and forbid cutting an
     * operation or a target in half — a truncated name would group unrelated queries.
     */
    #[Test]
    public function aLongSummaryIsTruncatedBetweenTokens(): void
    {
        $tables = \array_map(static fn(int $i): string => \sprintf('table_%03d', $i), \range(1, 60));
        $summary = SqlSummary::of('SELECT * FROM ' . \implode(', ', $tables), 'postgresql')->value;

        self::assertNotNull($summary);
        self::assertLessThanOrEqual(255, \strlen($summary));
        self::assertStringStartsWith('SELECT table_001 table_002', $summary);
        self::assertMatchesRegularExpression('~ table_\d{3}$~', $summary, 'the last token is whole');
    }

    /**
     * A bulk insert is the longest statement an application sends. Its values are data,
     * and none of them may reach the summary, however many there are.
     */
    #[Test]
    public function aBulkInsertSummarisesToItsTarget(): void
    {
        $rows = \implode(', ', \array_fill(0, 5000, "(1, 'from x', 'it''s into y')"));

        foreach (self::SYSTEMS as $system) {
            self::assertSame(
                'INSERT events',
                SqlSummary::of('INSERT INTO events (a, b, c) VALUES ' . $rows, $system)->value,
                $system,
            );
        }
    }
}
