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
}
