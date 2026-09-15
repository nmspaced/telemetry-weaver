<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Driver\Result;

/**
 * @internal
 */
final class FakeDbalResult implements Result
{
    /** @return list<mixed>|false */
    #[\Override]
    public function fetchNumeric(): array|false
    {
        return false;
    }

    /** @return array<string, mixed>|false */
    #[\Override]
    public function fetchAssociative(): array|false
    {
        return false;
    }

    #[\Override]
    public function fetchOne(): mixed
    {
        return false;
    }

    /** @return list<list<mixed>> */
    #[\Override]
    public function fetchAllNumeric(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    #[\Override]
    public function fetchAllAssociative(): array
    {
        return [];
    }

    /** @return list<mixed> */
    #[\Override]
    public function fetchFirstColumn(): array
    {
        return [];
    }

    #[\Override]
    public function rowCount(): int
    {
        return 0;
    }

    #[\Override]
    public function columnCount(): int
    {
        return 0;
    }

    #[\Override]
    public function free(): void {}
}
