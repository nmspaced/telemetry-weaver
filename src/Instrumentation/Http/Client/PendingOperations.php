<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;

/**
 * The requests one client has started and not yet finished.
 *
 * Weakly keyed, and that is the whole design. The only strong reference to an operation
 * is the closure the response holds, so a response the application drops before reading
 * it takes its operation with it instead of leaving an entry behind in a service that
 * lives for the whole process — which, over a worker's thousands of requests, is the
 * difference between a bounded set and a leak. It also means this class cannot be asked
 * how many requests are outstanding: whatever is still here is whatever is still
 * reachable, which is exactly the set a reset has to deal with.
 *
 * `abandonAll()` is the worker boundary. Each operation ends its span, because an
 * unended span keeps its context scope activated, and records no duration, because a
 * request nobody saw finish did not take that long. The map is emptied before anything
 * is abandoned, so a throwing operation cannot leave a half-dead entry for the next
 * lookup to find.
 *
 * One instance per client, never shared: resetting one client must not end another
 * client's requests.
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
