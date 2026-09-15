<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * @internal
 */
final readonly class FakeDbalConnection implements DriverConnection
{
    use FakeDbalConnectionStatements;

    use FakeDbalConnectionTransactions;

    public function __construct(
        private FakeDbalDriver $driver,
    ) {}

    /** The driver behind this connection, so a test can make its next call fail. */
    public function driver(): FakeDbalDriver
    {
        return $this->driver;
    }

    #[\Override]
    public function lastInsertId(): int
    {
        return 0;
    }

    #[\Override]
    public function getServerVersion(): string
    {
        return '3.45.0';
    }

    #[\Override]
    public function getNativeConnection(): object
    {
        return $this;
    }
}
