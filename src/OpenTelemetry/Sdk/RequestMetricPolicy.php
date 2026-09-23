<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;

/**
 * @internal Whether a pipeline that lives for one request exports metrics, and with which temporality.
 *
 * Off unless `runtime.request_metrics.mode: delta`. A Counter from a pipeline that starts with
 * every request restarts at zero on every request, so the shape it is exported in is
 * `MetricTemporality::lowMemory()`:
 *
 *  - Counter and Histogram are delta: each request contributes exactly what it recorded.
 *  - UpDownCounters and gauges stay cumulative and describe that request alone.
 *  - An asynchronous Counter stays cumulative: its delta would be computed against a previous
 *    observation that a pipeline built for this request does not have.
 *
 * Whether the backend accepts delta, and whether the resource tells writers apart, is the
 * deployment's to get right; nothing here second-guesses the configuration.
 */
final readonly class RequestMetricPolicy
{
    private function __construct(
        private SymfonyRuntimeProfile $runtime,
        private string $mode,
    ) {}

    /**
     * @param 'disabled'|'delta' $mode
     */
    public static function forRuntime(SymfonyRuntimeProfile $runtime, string $mode): self
    {
        return new self($runtime, $mode);
    }

    /**
     * null: this is not a request pipeline, and the exporter's own preference applies.
     */
    public function selector(): ?MetricTemporality
    {
        return $this->runtime->finishesAfterRequest() ? MetricTemporality::lowMemory() : null;
    }

    public function allows(): bool
    {
        return !$this->runtime->finishesAfterRequest() || $this->mode !== 'disabled';
    }
}
