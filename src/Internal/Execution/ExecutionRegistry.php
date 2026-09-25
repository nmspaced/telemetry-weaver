<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Execution;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-execution state, keyed weakly so a missed terminate cannot leak in a worker.
 * `reset()` (via `kernel.reset`) abandons whatever is still open.
 *
 * @template TEntry of ExecutionEntry
 */
final class ExecutionRegistry implements ResetInterface
{
    /**
     * @var \WeakMap<object, TEntry>
     */
    private \WeakMap $entries;

    /**
     * @param non-empty-string $where what to name in a diagnostic, e.g. the signal this registry serves
     */
    public function __construct(
        private readonly InstrumentationFailureReporter $reporter,
        private readonly string $where,
    ) {
        /** @var \WeakMap<object, TEntry> $entries */
        $entries = new \WeakMap();
        $this->entries = $entries;
    }

    /**
     * The entry for $key, created on first call and reused afterwards.
     *
     * @param \Closure(): TEntry $create
     *
     * @return TEntry
     */
    public function open(object $key, \Closure $create): ExecutionEntry
    {
        $existing = $this->entries[$key] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        return $this->entries[$key] = $create();
    }

    /**
     * @return TEntry|null
     */
    public function of(object $key): ?ExecutionEntry
    {
        return $this->entries[$key] ?? null;
    }

    /** Forgets the entry before completing it, so a failure cannot leave it behind. */
    public function close(object $key): void
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->entries[$key]);
        $entry->complete();
    }

    /** Abandons every open entry, innermost first; one failure does not stop the rest. */
    #[\Override]
    public function reset(): void
    {
        $entries = [];

        foreach ($this->entries as $entry) {
            $entries[] = $entry;
        }

        /** @var \WeakMap<object, TEntry> $empty */
        $empty = new \WeakMap();
        $this->entries = $empty;

        foreach (\array_reverse($entries) as $entry) {
            try {
                $entry->abandon();
            } catch (\Throwable $e) {
                $this->reporter->report('abandoning an unfinished execution failed', $this->where, $e);
            }
        }
    }
}
