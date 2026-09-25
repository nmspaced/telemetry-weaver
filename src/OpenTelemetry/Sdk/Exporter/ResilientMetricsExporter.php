<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

final readonly class ResilientMetricsExporter implements
    PushMetricExporterInterface,
    AggregationTemporalitySelectorInterface
{
    public function __construct(
        private MetricExporterInterface $delegate,
        private ExportFailureReporter $reporter,
        private ExportGate $gate,
        private ?AggregationTemporalitySelectorInterface $selector = null,
    ) {}

    #[\Override]
    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        if ($this->selector !== null) {
            return $this->selector->temporality($metric);
        }

        if ($this->delegate instanceof AggregationTemporalitySelectorInterface) {
            return $this->delegate->temporality($metric);
        }

        return $metric->temporality();
    }

    /**
     * Sends only metrics with data points; the Prometheus OTLP receiver rejects a request with
     * an empty one.
     *
     * @param iterable<Metric> $batch
     */
    #[\Override]
    public function export(iterable $batch): bool
    {
        if (!$this->gate->allowsExport()) {
            return false;
        }

        $metrics = \array_filter(\iterator_to_array($batch, false), self::hasDataPoints(...));

        return $metrics === [] || $this->send($metrics);
    }

    #[\Override]
    public function shutdown(): bool
    {
        if (!$this->gate->allowsExport()) {
            return false;
        }

        try {
            return $this->delegate->shutdown();
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to shut down metrics exporter', $throwable);

            return false;
        }
    }

    #[\Override]
    public function forceFlush(): bool
    {
        if (!$this->gate->allowsExport()) {
            return false;
        }

        if (!$this->delegate instanceof PushMetricExporterInterface) {
            return true;
        }

        try {
            return $this->delegate->forceFlush();
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to force flush metrics exporter', $throwable);

            return false;
        }
    }

    /** @param array<int, Metric> $metrics */
    private function send(array $metrics): bool
    {
        try {
            return $this->delegate->export($metrics);
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to export metrics', $throwable);

            return false;
        }
    }

    private static function hasDataPoints(Metric $metric): bool
    {
        return $metric->data->dataPointCount() > 0;
    }
}
