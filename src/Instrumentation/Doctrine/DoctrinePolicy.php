<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\API\Trace\Span;

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
    public function __construct(
        public bool $recordStatements = false,
        private bool $onlyWithParent = true,
        public bool $recordTransactions = true,
    ) {}

    /**
     * Whether this statement gets a span.
     *
     * `only_with_parent` exists to keep orphan spans of infrastructure activity —
     * Messenger transport polling is the usual one — out of traces. It deliberately
     * says nothing about metrics: that load is real load on the database, it carries
     * no cardinality risk, and dropping it would undercount what the database does.
     */
    public function opensSpan(): bool
    {
        return !$this->onlyWithParent || Span::getCurrent()->getContext()->isValid();
    }
}
