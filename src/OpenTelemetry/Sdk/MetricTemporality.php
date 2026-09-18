<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;

/**
 * @internal Aggregation temporality per instrument kind, chosen before the first measurement.
 *
 * Replaces what the SDK derives from `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE`,
 * because the installed OTLP exporter factory gets it wrong in both non-default modes:
 *
 *  - `delta` becomes `Temporality::DELTA` for every instrument. An UpDownCounter or an
 *    observable UpDownCounter is a current state — `php.memory.usage`, active requests — and
 *    exported as delta it turns into a chain of increments whose absolute value exists only
 *    inside a stateful `deltatocumulative` downstream. When that processor restarts, the
 *    baseline is gone and every reconstructed value after it is wrong, silently.
 *  - `lowmemory` becomes `null`, which falls back to the stream's own temporality: DELTA for
 *    every synchronous instrument, the UpDownCounter included.
 *
 * The specification's table is what is implemented here instead:
 *
 *   | instrument                     | cumulative | delta      | lowmemory  |
 *   |--------------------------------|------------|------------|------------|
 *   | Counter, Histogram             | Cumulative | Delta      | Delta      |
 *   | Asynchronous Counter           | Cumulative | Delta      | Cumulative |
 *   | UpDownCounter (both kinds)     | Cumulative | Cumulative | Cumulative |
 *   | Gauge (both kinds)             | Cumulative | Cumulative | Cumulative |
 *
 * Gauges have no temporality on the wire; Cumulative is the stream's natural mode for an
 * observation and keeps the last value, which is what a gauge means.
 *
 * Never `null`. `ExportingReader` does not read `null` as "use the default" — it skips
 * registering the metric source, so the instrument records into nothing and nothing says so.
 * An instrument kind this class does not know yet is exported Cumulative, the one mode that
 * cannot misrepresent a value, rather than disappearing.
 */
final readonly class MetricTemporality implements AggregationTemporalitySelectorInterface
{
    /**
     * @param Temporality::* $synchronousMonotonic Counter and Histogram
     * @param Temporality::* $asynchronousMonotonic Asynchronous Counter
     */
    private function __construct(
        private string $synchronousMonotonic,
        private string $asynchronousMonotonic,
    ) {}

    public static function cumulative(): self
    {
        return new self(Temporality::CUMULATIVE, Temporality::CUMULATIVE);
    }

    public static function delta(): self
    {
        return new self(Temporality::DELTA, Temporality::DELTA);
    }

    public static function lowMemory(): self
    {
        return new self(Temporality::DELTA, Temporality::CUMULATIVE);
    }

    /**
     * @param string $preference a value of OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE
     *
     * @throws \UnexpectedValueException for a value the specification does not define
     */
    public static function preferred(string $preference): self
    {
        return match (\strtolower($preference)) {
            'cumulative' => self::cumulative(),
            'delta' => self::delta(),
            'lowmemory' => self::lowMemory(),
            default => throw new \UnexpectedValueException('Unknown metrics temporality preference: ' . $preference),
        };
    }

    /** @return Temporality::* */
    #[\Override]
    public function temporality(MetricMetadataInterface $metric): string
    {
        return match ($metric->instrumentType()) {
            InstrumentType::COUNTER, InstrumentType::HISTOGRAM => $this->synchronousMonotonic,
            InstrumentType::ASYNCHRONOUS_COUNTER => $this->asynchronousMonotonic,
            default => Temporality::CUMULATIVE,
        };
    }
}
