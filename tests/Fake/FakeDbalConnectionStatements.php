<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * The statement methods of {@see FakeDbalConnection}.
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
