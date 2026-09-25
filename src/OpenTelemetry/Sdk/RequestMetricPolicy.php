<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;

/**
 * @internal
 *
 * Whether a pipeline that lives for one request exports metrics, and with which temporality.
 * Off unless `runtime.request_metrics.mode: delta`.
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
     * Null outside a request pipeline, so the exporter's own preference applies.
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
