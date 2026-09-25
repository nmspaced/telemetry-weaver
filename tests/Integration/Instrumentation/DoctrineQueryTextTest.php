<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\QueryText;
use Nmspaced\TelemetryWeaver\Tests\Support\DoctrineTelemetryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a statement span says about the SQL: never a literal or a comment in its name or summary,
 * and `db.query.text` in the form `query_text` asks for.
 */
final class DoctrineQueryTextTest extends DoctrineTelemetryTestCase
{
    /** @return iterable<string, array{'pdo_mysql'|'pdo_pgsql', string}> */
    public static function hiddenText(): iterable
    {
        yield 'mysql double-quoted literal' => ['pdo_mysql', 'SELECT "FROM SYNTHETIC_SECRET" AS message'];
        yield 'mysql hash comment' => ['pdo_mysql', 'SELECT 1 # FROM SYNTHETIC_SECRET'];
        yield 'mysql hash comment after a number' => ['pdo_mysql', 'SELECT 1# FROM SYNTHETIC_SECRET'];
        yield 'postgres nested comment' => ['pdo_pgsql', 'SELECT 1 /* outer /* nested */ FROM SYNTHETIC_SECRET */'];
        yield 'mysql backslash ambiguity' => ['pdo_mysql', "SELECT 'a\\' FROM SYNTHETIC_SECRET -- '"];
    }

    /**
     * @param 'pdo_mysql'|'pdo_pgsql' $driver
     *
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('hiddenText')]
    public function nothingALiteralOrACommentHidesIsExported(string $driver, string $sql): void
    {
        $this->connection(driver: $driver)->executeQuery($sql);

        $span = $this->exportedSpan();
        $exported = \json_encode([$span->getName(), $span->getAttributes()->toArray()], \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('SYNTHETIC_SECRET', $exported);
    }

    /** @throws \Throwable */
    #[Test]
    public function theStatementTextIsSanitizedByDefault(): void
    {
        $this->connection()->executeQuery("SELECT id FROM users WHERE name = 'SYNTHETIC_SECRET' AND age > 30 LIMIT 5");

        self::assertSame(
            'SELECT id FROM users WHERE name = ? AND age > ? LIMIT ?',
            $this->exportedSpan()->getAttributes()->get('db.query.text'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aStatementThatCannotBeSanitizedCarriesNoText(): void
    {
        $this->connection()->executeQuery("SELECT 'a\\' FROM SYNTHETIC_SECRET -- '");

        self::assertNull($this->exportedSpan()->getAttributes()->get('db.query.text'));
    }

    /** @return iterable<string, array{QueryText, string|null}> */
    public static function queryTextModes(): iterable
    {
        yield 'raw' => [QueryText::Raw, "SELECT id FROM users WHERE name = 'x'"];
        yield 'off' => [QueryText::Off, null];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('queryTextModes')]
    public function theOtherModesRecordTheStatementAsSentOrNothing(QueryText $mode, ?string $expected): void
    {
        $this->connection(new DoctrinePolicy(queryText: $mode, onlyWithParent: false))
            ->executeQuery("SELECT id FROM users WHERE name = 'x'");

        self::assertSame($expected, $this->exportedSpan()->getAttributes()->get('db.query.text'));
    }
}
