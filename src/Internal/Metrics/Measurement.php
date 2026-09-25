<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

/**
 * One interval being measured, until it is recorded or dropped.
 *
 * @internal
 */
interface Measurement
{
    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function stop(array $attributes = []): void;

    public function cancel(): void;

    /** Stops the clock until `resume()`; idempotent, and a no-op once ended. */
    public function pause(): void;

    /** Restarts a paused clock. Idempotent, and a no-op unless paused. */
    public function resume(): void;
}
