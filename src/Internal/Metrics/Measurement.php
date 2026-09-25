<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

/**
 * One interval being measured, from the moment it started until it is recorded or dropped.
 *
 * Internal rather than public: an operation starts and finishes its own measurement, so
 * application code never holds one. What does hold one is framework instrumentation whose
 * interval is not an operation at all — request metrics span a request that may have no
 * span, because metrics keep working when tracing is switched off.
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

    /**
     * Stops the clock without ending the interval. Time until `resume()` is not measured.
     *
     * For work that is handed out in pieces, such as a lazy result: the time a consumer
     * spends between two pieces is the consumer's own work and not part of the operation
     * being measured. Idempotent, and a no-op once the interval has ended.
     */
    public function pause(): void;

    /** Restarts a paused clock. Idempotent, and a no-op unless paused. */
    public function resume(): void;
}
