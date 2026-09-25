<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;

/**
 * @internal
 *
 * Aggregation temporality per instrument kind, following the OTLP exporter specification
 * rather than the SDK factory, which also makes UpDownCounters delta. Never returns null:
 * `ExportingReader` would silently drop the instrument.
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
