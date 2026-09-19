<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * What the Doctrine instrumentation records, as opposed to whether it records at all.
 *
 * Neither signal has an on/off flag here on purpose: a disabled signal is expressed by
 * the objects injected into `DoctrineTelemetry` — a no-op span opener, a no-op meter —
 * not by a branch this class would have to be asked about on every query.
 *
 * What is left are genuine filters. Each changes which telemetry is worth producing for
 * a connection that is being instrumented.
 */
final readonly class DoctrinePolicy
{
    /**
     * @param bool $recordStatements record the SQL text as `db.query.text`
     * @param bool $onlyWithParent open spans only inside an existing trace
     * @param bool $recordTransactions record BEGIN, COMMIT and ROLLBACK as operations of their own
     */
    /**
     * @param bool $onlyWithParent open spans only inside an existing trace. Kept as the
     *                             plain configuration value it is: whether a trace is
     *                             running is not this object's to look up, and asking it to
     *                             made a value object depend on the current execution.
     *                             {@see \Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryOperation::onlyInsideTrace()}
     *                             carries the decision to the one place that owns the context.
     */
    public function __construct(
        public bool $recordStatements = false,
        public bool $onlyWithParent = true,
        public bool $recordTransactions = true,
    ) {}
}
