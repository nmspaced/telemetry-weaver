<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * The `DriverConnection` methods that run SQL, split out of {@see FakeDbalConnection} to keep
 * that class under the method-count budget. Transaction control lives in
 * {@see FakeDbalConnectionTransactions}.
 *
 * @internal
 *
 * @property-read FakeDbalDriver $driver
 *
 * @require-implements \Doctrine\DBAL\Driver\Connection
 */
trait FakeDbalConnectionStatements
{
    /** @throws FakeDriverException */
    #[\Override]
    public function prepare(string $sql): Statement
    {
        return new FakeDbalStatement($this->driver, $sql);
    }

    /** @throws FakeDriverException */
    #[\Override]
    public function query(string $sql): Result
    {
        $this->driver->run($sql);

        return new FakeDbalResult();
    }

    #[\Override]
    public function quote(string $value): string
    {
        return \sprintf("'%s'", $value);
    }

    /** @throws FakeDriverException */
    #[\Override]
    public function exec(string $sql): int
    {
        $this->driver->run($sql);

        return 0;
    }
}
