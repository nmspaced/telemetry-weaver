<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

/**
 * The transaction methods of {@see FakeDbalConnection}.
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
