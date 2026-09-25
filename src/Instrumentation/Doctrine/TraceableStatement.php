<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Spans each execution of a prepared statement. It can outlive a request, so it holds only
 * the SQL and immutable connection attributes. Bound parameter values are never recorded.
 */
final class TraceableStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly string $sql,
        private readonly ConnectionAttributes $attributes,
        private readonly DoctrineTelemetry $doctrineTelemetry,
    ) {
        parent::__construct($statement);
    }

    /** @throws \Throwable */
    #[\Override]
    public function execute(): Result
    {
        return $this->doctrineTelemetry->run($this->sql, $this->attributes, parent::execute(...));
    }
}
