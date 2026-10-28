<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/** Application code that queries the database, as a repository would. */
final readonly class OrderRepository
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return int the line the query was issued on
     *
     * @throws Exception
     */
    public function findOrder(): int
    {
        $this->connection->executeQuery('SELECT id FROM orders WHERE id = 7');

        return __LINE__ - 2;
    }
}
