<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * @internal
 */
final readonly class FakeDbalStatement implements Statement
{
    public function __construct(
        private FakeDbalDriver $driver,
        private string $sql,
    ) {}

    #[\Override]
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void {}

    /** @throws FakeDriverException */
    #[\Override]
    public function execute(): Result
    {
        $this->driver->run($this->sql);

        return new FakeDbalResult();
    }
}
