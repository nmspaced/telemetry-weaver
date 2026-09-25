<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * What the Doctrine instrumentation records. Whether a signal is on is decided by the
 * injected opener and meter, not here.
 */
final readonly class DoctrinePolicy
{
    /**
     * @param QueryText $queryText what `db.query.text` carries
     * @param bool $onlyWithParent open spans only inside an existing trace
     * @param bool $recordTransactions record BEGIN, COMMIT and ROLLBACK as operations of their own
     */
    public function __construct(
        public QueryText $queryText = QueryText::Sanitized,
        public bool $onlyWithParent = true,
        public bool $recordTransactions = true,
    ) {}
}
