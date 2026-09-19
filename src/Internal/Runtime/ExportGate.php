<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

/**
 * @internal The one terminal "no more export" flag of a pipeline, and the pipeline's only PHP shutdown hook.
 *
 * Every exporter wrapper and every budgeted transport of one container asks the same gate
 * before it calls out. That is what makes finalization a single decision: once the gate is
 * closed, a batch processor draining late, a user callback calling `shutdown()` again, or a
 * delayed transport send all stop at the bundle's boundary instead of reaching a collector
 * after the budget is gone. Closing needs no I/O and cannot be undone.
 *
 * PHP shutdown is handled here rather than through the SDK's `ShutdownHandler`, which would
 * export once per provider with no shared deadline. What happens at shutdown depends on who
 * is waiting:
 *
 *  - A request pipeline (FPM, a kernel-resetting worker) that reaches PHP shutdown still open
 *    missed its `kernel.terminate`: `exit()`, a fatal, an aborted stream. FPM runs shutdown
 *    functions before it releases the child, so exporting here would hold the child on the
 *    collector. The gate is closed and the telemetry is discarded.
 *  - A long-lived pipeline (an HTTP worker keeping its kernel, a console process) reaches PHP
 *    shutdown after its last request, when the runner's loop returns. There is no Symfony event
 *    for that, and without a final flush every worker recycle — `max_requests`, a deploy, a
 *    reload — drops up to one schedule interval of spans and metrics. The flusher registered
 *    through `finishOnExit()` runs a final, budgeted `atShutdown()`, unless the process is
 *    dying of a fatal error: its memory or state cannot be trusted to run exporter code.
 *
 * The registry of open gates is process state on purpose, and bounded: it is weakly keyed, a
 * closed gate removes itself, and the flusher is held through a `\WeakReference`, so a
 * replaced container (worker mode 2 clones the kernel after each request) is not kept alive
 * by a callback that runs only when the process ends.
 */
final class ExportGate
{
    private const int FATAL_ERRORS = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;

    /** @var \WeakMap<self, null>|null */
    private static ?\WeakMap $live = null;

    private bool $closed = false;

    /** @var \WeakReference<BoundaryFlush>|null */
    private ?\WeakReference $finisher = null;

    private function __construct(
        private readonly FlushBudget $budget,
    ) {}

    public static function forBudget(FlushBudget $budget): self
    {
        $gate = new self($budget);
        if (self::$live === null) {
            /** @var \WeakMap<self, null> $live */
            $live = new \WeakMap();
            self::$live = $live;
            \register_shutdown_function(self::atProcessExit(...));
        }

        self::$live[$gate] = null;

        return $gate;
    }

    public function allowsExport(): bool
    {
        return !$this->closed && !$this->budget->exhausted();
    }

    /** The flush budget transports divide between their destinations. */
    public function budget(): FlushBudget
    {
        return $this->budget;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->finisher = null;
        unset(self::$live[$this]);
    }

    /** A pipeline that outlives requests gets one final, budgeted flush when PHP shuts down. */
    public function finishOnExit(BoundaryFlush $flusher): void
    {
        if ($this->closed) {
            return;
        }

        $this->finisher = \WeakReference::create($flusher);
    }

    private static function atProcessExit(): void
    {
        // Copied first: finishing closes the gate, which removes it from the map being walked.
        $gates = [];
        foreach (self::$live ?? [] as $gate => $_) {
            $gates[] = $gate;
        }

        $error = \error_get_last();
        $fatal = $error !== null && ($error['type'] & self::FATAL_ERRORS) !== 0;
        foreach ($gates as $gate) {
            try {
                if (!$fatal) {
                    $gate->finisher?->get()?->atShutdown();
                }
            } finally {
                $gate->close();
            }
        }
    }
}
