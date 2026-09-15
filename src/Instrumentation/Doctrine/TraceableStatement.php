<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Spans one execution of a prepared statement.
 *
 * A prepared statement is reused, so this object can outlive a request — which is
 * exactly why it holds only the SQL string and the immutable connection attributes.
 * The span is opened and closed inside `execute()`, so nothing about an execution
 * survives it and no `kernel.reset` has anything to release here.
 *
 * Bound parameter values are never recorded: `db.query.parameter.<key>` is where they
 * would go, and the conventions say not to capture it by default because of PII.
 *
 * Not readonly because `AbstractStatementMiddleware` is not.
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
