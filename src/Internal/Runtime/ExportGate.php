<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

/**
 * A pipeline's irreversible "no more export" switch and its only PHP shutdown hook. At
 * shutdown, request pipelines are closed without exporting; long-lived ones registered via
 * `finishOnExit()` get one final budgeted flush unless the process died of a fatal error.
 *
 * @internal
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
