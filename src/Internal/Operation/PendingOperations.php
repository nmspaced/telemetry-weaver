<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;

/**
 * Operations a decorator has started and handed to lazy work that has not finished yet: an
 * HTTP response nobody has read, a cache batch nobody has iterated.
 *
 * Weakly keyed, and that is the whole design. The only strong reference to an operation
 * is the lazy result that holds it, so a result the application drops before finishing it
 * takes its operation with it instead of leaving an entry behind in a service that lives
 * for the whole process. Over a worker's thousands of requests, that is the difference
 * between a bounded set and a leak. A dropped operation records nothing, which is the
 * truth: nobody saw it finish. It also means this class cannot be asked how many
 * operations are outstanding: whatever is still here is whatever is still reachable,
 * which is exactly the set a reset has to deal with.
 *
 * `abandonAll()` is the worker boundary. Each operation ends its span, because an
 * unended span keeps its context scope activated, and records no duration, because work
 * nobody saw finish did not take that long. The map is emptied before anything is
 * abandoned, so a throwing operation cannot leave a half-dead entry for the next lookup to
 * find.
 *
 * One instance per resettable service, never shared between services: resetting one
 * client must not end another client's requests.
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
