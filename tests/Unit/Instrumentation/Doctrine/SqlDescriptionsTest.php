<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Doctrine;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlDescriptions;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlLexer;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlQueryText;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\SqlSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A remembered description is the one computing it again would give. */
#[CoversClass(SqlDescriptions::class)]
final class SqlDescriptionsTest extends TestCase
{
    private const string SQL = "SELECT id FROM orders WHERE status = 'paid' AND total > 10";

    #[Test]
    public function aRepeatedStatementIsDescribedAsTheFirstTimeWas(): void
    {
        $descriptions = new SqlDescriptions();
        $expected = [
            SqlSummary::of(self::SQL, 'postgresql')->value,
            SqlQueryText::sanitized(SqlLexer::code(self::SQL, 'postgresql')),
        ];

        self::assertSame($expected, $descriptions->describe(self::SQL, 'postgresql', true));
        self::assertSame($expected, $descriptions->describe(self::SQL, 'postgresql', true));
        self::assertSame(['SELECT orders', 'SELECT id FROM orders WHERE status = ? AND total > ?'], $expected);
    }

    #[Test]
    public function theSanitizedTextIsLeftOutWhenNotAskedForAndRememberedApart(): void
    {
        $descriptions = new SqlDescriptions();

        self::assertSame(['SELECT orders', null], $descriptions->describe(self::SQL, 'postgresql', false));
        self::assertNotNull($descriptions->describe(self::SQL, 'postgresql', true)[1]);
        self::assertSame(['SELECT orders', null], $descriptions->describe(self::SQL, 'postgresql', false));
    }

    #[Test]
    public function theDatabaseSystemIsPartOfWhatIsRemembered(): void
    {
        $descriptions = new SqlDescriptions();
        $sql = 'SELECT "x" FROM t';

        self::assertSame(
            SqlQueryText::sanitized(SqlLexer::code($sql, 'postgresql')),
            $descriptions->describe($sql, 'postgresql', true)[1],
        );
        self::assertSame(
            SqlQueryText::sanitized(SqlLexer::code($sql, 'mysql')),
            $descriptions->describe($sql, 'mysql', true)[1],
            'MySQL reads "x" as a string; a description remembered for PostgreSQL must not answer',
        );
    }

    /** @throws \ReflectionException */
    #[Test]
    public function itStaysBoundedAndStillAnswersCorrectlyPastItsCapacity(): void
    {
        $descriptions = new SqlDescriptions();

        for ($i = 0; $i < 600; ++$i) {
            $descriptions->describe(\sprintf('SELECT id FROM t%d', $i), 'postgresql', true);
        }

        $remembered = new \ReflectionProperty(SqlDescriptions::class, 'described')->getValue($descriptions);
        self::assertIsArray($remembered);
        self::assertLessThanOrEqual(256, \count($remembered));
        self::assertSame(
            ['SELECT t3', 'SELECT id FROM t3'],
            $descriptions->describe('SELECT id FROM t3', 'postgresql', true),
        );
    }
}
