<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Doctrine;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\ConnectionAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DatabaseSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseSystem::class)]
#[CoversClass(ConnectionAttributes::class)]
final class DatabaseSystemTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function drivers(): iterable
    {
        yield 'pdo_mysql' => ['pdo_mysql', 'mysql'];
        yield 'mysqli' => ['mysqli', 'mysql'];
        yield 'pdo_pgsql' => ['pdo_pgsql', 'postgresql'];
        yield 'pdo_sqlite' => ['pdo_sqlite', 'sqlite'];
        yield 'pdo_sqlsrv' => ['pdo_sqlsrv', 'microsoft.sql_server'];
        yield 'oci8' => ['oci8', 'oracle.db'];
        yield 'ibm_db2' => ['ibm_db2', 'ibm.db2'];
        yield 'unknown driver' => ['pdo_firebird', 'other_sql'];
        yield 'driverClass only' => [null, 'other_sql'];
        yield 'not a string' => [42, 'other_sql'];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function theDbalDriverNameBecomesASemanticConventionSystemName(mixed $driver, string $system): void
    {
        self::assertSame($system, DatabaseSystem::of($driver));
    }

    /**
     * Optional parameters are omitted rather than written empty: an attribute that is
     * present but blank is worse than an absent one, and sqlite has neither host nor
     * database name.
     */
    #[Test]
    public function onlyTheParametersThatExistBecomeAttributes(): void
    {
        $attributes = ConnectionAttributes::fromParams(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertSame(['db.system.name' => 'sqlite'], $attributes->attributes());
    }

    #[Test]
    public function aFullyConfiguredConnectionCarriesTheServerAndTheNamespace(): void
    {
        $attributes = ConnectionAttributes::fromParams([
            'driver' => 'pdo_pgsql',
            'dbname' => 'app',
            'host' => 'db.internal',
            'port' => 5432,
        ]);

        self::assertSame(
            [
                'db.system.name' => 'postgresql',
                'db.namespace' => 'app',
                'server.address' => 'db.internal',
                'server.port' => 5432,
            ],
            $attributes->attributes(),
        );
    }
}
