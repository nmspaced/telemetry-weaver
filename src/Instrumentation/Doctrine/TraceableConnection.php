<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Spans the calls that reach the server directly: `query()`, `exec()`, and the three
 * transaction boundaries.
 *
 * `prepare()` is not one of them: preparing a statement is not a round trip worth a
 * span of its own, and the work it describes is measured when the statement executes.
 * It only wraps the statement so that execution can be.
 *
 * Not readonly because `AbstractConnectionMiddleware` is not; nothing here is mutable.
 */
final class TraceableConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly ConnectionAttributes $attributes,
        private readonly DoctrineTelemetry $doctrineTelemetry,
    ) {
        parent::__construct($connection);
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function query(string $sql): Result
    {
        return $this->doctrineTelemetry->run(
            $sql,
            $this->attributes,
            /** @throws Exception */
            fn(): Result => parent::query($sql),
        );
    }

    /**
     * @return int|numeric-string
     *
     * @throws \Throwable
     */
    #[\Override]
    public function exec(string $sql): int|string
    {
        return $this->doctrineTelemetry->run(
            $sql,
            $this->attributes,
            /**
             * @return int|numeric-string
             *
             * @throws Exception
             */
            fn(): int|string => parent::exec($sql),
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function beginTransaction(): void
    {
        $this->doctrineTelemetry->transaction(
            DoctrineTelemetry::TRANSACTION_BEGIN,
            $this->attributes,
            /** @throws Exception */
            function (): void {
                parent::beginTransaction();
            },
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function commit(): void
    {
        $this->doctrineTelemetry->transaction(
            DoctrineTelemetry::TRANSACTION_COMMIT,
            $this->attributes,
            /** @throws Exception */
            function (): void {
                parent::commit();
            },
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function rollBack(): void
    {
        $this->doctrineTelemetry->transaction(
            DoctrineTelemetry::TRANSACTION_ROLLBACK,
            $this->attributes,
            /** @throws Exception */
            function (): void {
                parent::rollBack();
            },
        );
    }

    /**
     * @throws Exception
     */
    #[\Override]
    public function prepare(string $sql): Statement
    {
        return new TraceableStatement(parent::prepare($sql), $sql, $this->attributes, $this->doctrineTelemetry);
    }
}
