<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\API\SQLite\ExceptionConverter as SQLiteExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * A driver that answers every statement with an empty result, and rejects any statement
 * naming a table it was told does not exist.
 *
 * It exists because the PHP this suite runs on has no SQLite extension, so a real
 * database is not available. Everything above the driver is still real — DriverManager,
 * Configuration::setMiddlewares(), the DBAL Connection and the middleware chain — so
 * what the fake removes is the database, not the wiring under test.
 */
final class FakeDbalDriver implements Driver
{
    /** @var list<string> */
    public array $executed = [];

    /** Makes the next driver-level commit fail, as a lost connection would. */
    public bool $failCommit = false;

    /** @param list<string> $missingTables tables that make a statement fail */
    public function __construct(
        private readonly array $missingTables = ['missing_table'],
    ) {}

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function connect(array $params): DriverConnection
    {
        return new FakeDbalConnection($this);
    }

    #[\Override]
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return new SQLitePlatform();
    }

    #[\Override]
    public function getExceptionConverter(): ExceptionConverter
    {
        return new SQLiteExceptionConverter();
    }

    /** @throws FakeDriverException */
    public function commit(): void
    {
        if (!$this->failCommit) {
            return;
        }

        $this->failCommit = false;

        throw new FakeDriverException('connection lost during commit');
    }

    /** @throws FakeDriverException */
    public function run(string $sql): void
    {
        $this->executed[] = $sql;

        foreach ($this->missingTables as $table) {
            if (\str_contains($sql, $table)) {
                throw new FakeDriverException(\sprintf('no such table: %s', $table));
            }
        }
    }
}
