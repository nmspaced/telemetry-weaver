<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

/**
 * The pipeline lifetime implied by `kernel.runtime_mode.web` and `.worker`: worker `0`
 * (FPM) and `2` (kernel cloned per request) finish per request, `1` keeps the pipeline until
 * PHP shutdown, and `web=false` (Console, Messenger) lets their own events decide.
 *
 * @internal
 */
final readonly class SymfonyRuntimeProfile
{
    private const int SHARED_KERNEL = 1;

    private function __construct(
        private int $workerMode,
        private bool $web,
    ) {}

    public static function fromKernel(int $workerMode, bool $web): self
    {
        return new self($workerMode, $web);
    }

    public function finishesAfterRequest(): bool
    {
        return $this->web && $this->workerMode !== self::SHARED_KERNEL;
    }

    /** A pipeline nobody finishes per request is finished, within the budget, when PHP shuts down. */
    public function finishesAtProcessExit(): bool
    {
        return !$this->finishesAfterRequest();
    }

    /** Whether a finished console command is a boundary; not under a web runtime, where it may run inside a request. */
    public function commandsAreBoundaries(): bool
    {
        return !$this->web;
    }

    public function hasWorkerIdentity(): bool
    {
        return !$this->web || $this->workerMode > 0;
    }

    public function recordsWorkerMetrics(): bool
    {
        return !$this->finishesAfterRequest();
    }
}
