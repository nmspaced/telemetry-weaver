<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;

/**
 * Operations handed to lazy work that has not finished, held weakly so a dropped result
 * takes its operation with it. `abandonAll()` ends the rest at the worker boundary without
 * recording a duration. One instance per resettable service.
 *
 * @internal
 */
final class PendingOperations
{
    /** @var \WeakMap<RunningOperation, true> */
    private \WeakMap $operations;

    public function __construct()
    {
        /** @var \WeakMap<RunningOperation, true> $operations */
        $operations = new \WeakMap();
        $this->operations = $operations;
    }

    public function add(RunningOperation $operation): void
    {
        $this->operations[$operation] = true;
    }

    public function release(RunningOperation $operation): void
    {
        unset($this->operations[$operation]);
    }

    /** Releases the operation, then ends it; the error, if any, marks it failed. */
    public function finish(RunningOperation $operation, ?\Throwable $error = null): void
    {
        $this->release($operation);
        $operation->finish($error);
    }

    /** Releases the operation, then ends it without recording a duration. */
    public function abandon(RunningOperation $operation): void
    {
        $this->release($operation);
        $operation->abandon();
    }

    public function abandonAll(): void
    {
        $pending = [];

        foreach ($this->operations as $operation => $_) {
            $pending[] = $operation;
        }

        /** @var \WeakMap<RunningOperation, true> $operations */
        $operations = new \WeakMap();
        $this->operations = $operations;

        foreach ($pending as $operation) {
            $operation->abandon();
        }
    }
}
