<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

/**
 * The `DriverConnection` transaction-control methods, split out of {@see FakeDbalConnection} to
 * keep that class under the method-count budget. Statement execution lives in
 * {@see FakeDbalConnectionStatements}.
 *
 * @internal
 *
 * @property-read FakeDbalDriver $driver
 *
 * @require-implements \Doctrine\DBAL\Driver\Connection
 */
trait FakeDbalConnectionTransactions
{
    #[\Override]
    public function beginTransaction(): void {}

    /** @throws FakeDriverException */
    #[\Override]
    public function commit(): void
    {
        $this->driver->commit();
    }

    #[\Override]
    public function rollBack(): void {}
}
