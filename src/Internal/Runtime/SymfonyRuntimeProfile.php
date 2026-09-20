<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

/**
 * @internal What Symfony's runtime parameters mean for the lifetime of the telemetry pipeline.
 *
 * The source is `kernel.runtime_mode.web` and `kernel.runtime_mode.worker`, resolved from
 * `APP_RUNTIME_MODE` when the container starts — not `PHP_SAPI`, and not a bundle switch. A
 * container warmed from the CLI keeps the env expressions, so the profile is only ever built
 * from the resolved values, never read in a compiler pass.
 *
 * `worker` is an int, and its values are not a boolean:
 *
 *  - `0` with `web`: one request per PHP execution (FPM). The pipeline is finished on terminate.
 *  - `1`: an HTTP worker keeping its kernel. The pipeline outlives requests; terminate is a
 *    scheduled boundary and PHP shutdown is the final one.
 *  - `2`: FrankenPHP's runner clones the kernel after terminate. The PHP execution lives on,
 *    the container and its providers do not, so the pipeline is finished per request like FPM
 *    — while the resource keeps the worker's identity, because the execution is still one writer.
 *  - Anything else is not known to keep the kernel, and is finished per request.
 *
 * `web=false` is Console and Messenger: their own events decide the boundaries.
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

    /**
     * Whether a finished command ends a unit of work of *this* pipeline.
     *
     * Console and Messenger are the runtime here, so their events decide the boundaries.
     * Under a web runtime they do not: a console Application run from a controller — with
     * the kernel's dispatcher, which is the ordinary programmatic call — dispatches the
     * same TERMINATE in the middle of a request the HTTP boundary still owns.
     */
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
