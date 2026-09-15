<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Execution;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-execution state, keyed weakly by the object the execution is about.
 *
 * Weak, because the registry must never be what keeps that object alive:
 * kernel.terminate is the normal end of an entry but it is not guaranteed —
 * exit(), a fatal, an aborted StreamedResponse all skip it — and in a worker
 * a strong reference from a process-lifetime service turns every such
 * execution into a permanent leak.
 *
 * reset() is the backstop, and it is the caller's job to run it: Symfony does
 * so through the kernel.reset tag, and a hand-written worker loop has to call
 * $kernel->reset() itself.
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
     * The entry for $key, creating it on first call. A second open() for the
     * same key hands back what is already there rather than replacing it —
     * two spans for one request would both be wrong.
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

    /**
     * Normal end: forget the entry first, so a throwing complete() cannot
     * leave a half-dead record behind for the next lookup to find.
     */
    public function close(object $key): void
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return;
        }

        unset($this->entries[$key]);
        $entry->complete();
    }

    /**
     * Releases whatever is still open, innermost first: a scope detached out
     * of order reports a mismatch, and the last one opened is the innermost.
     *
     * One entry failing must not strand the ones behind it — that is the whole
     * point of a backstop — so each is attempted on its own and a failure is
     * reported rather than swallowed.
     */
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
