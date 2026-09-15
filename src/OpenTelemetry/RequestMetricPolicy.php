<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\Incubating\Attributes\HostIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;

/**
 * @internal Whether a pipeline that lives for one request may export metrics, and with which temporality.
 *
 * Off unless `runtime.request_metrics.mode: delta`. A cumulative Counter from a pipeline that
 * starts with every request restarts at zero on every request, so the only honest shape for
 * Counter and Histogram is delta, chosen before aggregation.
 *
 * The temporality is `MetricTemporality::lowMemory()`, whatever the delta-compatible preference
 * says, and every instrument is still exported:
 *
 *  - Counter and Histogram are delta: each request contributes exactly what it recorded.
 *  - UpDownCounters and gauges stay cumulative. They are state, and a delta chain of state
 *    is only as correct as the stateful processor rebuilding it downstream. From a
 *    per-request pipeline they describe that request alone — a cumulative stream whose start
 *    time is the request's — which is a limited value, not a wrong one.
 *  - An asynchronous Counter stays cumulative even under an explicit `delta` preference.
 *    Delta for an observation is computed against the previous observation, and a pipeline
 *    built for this request has none: every request would report the whole observed total
 *    as its increment, and a downstream sum would count it once per request.
 *
 * An explicit `cumulative` preference conflicts and turns request metrics off with a
 * diagnostic, as does a resource without a writer identity. Uniqueness of that identity across
 * hosts and containers cannot be proven locally; it remains a deployment contract.
 */
final readonly class RequestMetricPolicy
{
    private function __construct(
        private SymfonyRuntimeProfile $runtime,
        private string $mode,
        private ExportFailureReporter $failures,
        private ResourceInfo $resource,
    ) {}

    /** @param 'disabled'|'delta' $mode */
    public static function forRuntime(
        SymfonyRuntimeProfile $runtime,
        string $mode,
        ExportFailureReporter $failures,
        ResourceInfo $resource,
    ): self {
        return new self($runtime, $mode, $failures, $resource);
    }

    /** null: this is not a request pipeline, and the exporter's own preference applies. */
    public function selector(): ?MetricTemporality
    {
        return $this->runtime->finishesAfterRequest() ? MetricTemporality::lowMemory() : null;
    }

    public function allows(): bool
    {
        if (!$this->runtime->finishesAfterRequest()) {
            return true;
        }

        if ($this->mode === 'disabled') {
            return false;
        }

        try {
            $variable = Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE;
            if (Configuration::has($variable) && \strtolower(Configuration::getEnum($variable)) === 'cumulative') {
                throw new \RuntimeException(
                    'Request metrics need delta counters and histograms; OTEL configuration asks for cumulative',
                );
            }

            if (!self::hasWriterIdentity($this->resource)) {
                throw new \RuntimeException(
                    'Request metrics need a service instance or process and host resource identity',
                );
            }

            return true;
        } catch (\Throwable $throwable) {
            $this->failures->record('Request metric export disabled', $throwable);

            return false;
        }
    }

    private static function hasWriterIdentity(ResourceInfo $resource): bool
    {
        $attributes = $resource->getAttributes()->toArray();
        /** @var mixed $instance */
        $instance = $attributes[ServiceIncubatingAttributes::SERVICE_INSTANCE_ID] ?? null;
        if (\is_string($instance) && $instance !== '') {
            return true;
        }

        /** @var mixed $pid */
        $pid = $attributes[ProcessIncubatingAttributes::PROCESS_PID] ?? null;
        /** @var mixed $host */
        $host =
            $attributes[HostIncubatingAttributes::HOST_ID] ?? $attributes[HostIncubatingAttributes::HOST_NAME] ?? null;

        return \is_int($pid) && \is_string($host) && $host !== '';
    }
}
