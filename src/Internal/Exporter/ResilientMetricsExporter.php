<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
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

    #[\Override]
    public function export(iterable $batch): bool
    {
        if (!$this->gate->allowsExport()) {
            return false;
        }

        try {
            return $this->delegate->export($batch);
        } catch (\Throwable $throwable) {
            $this->reporter->record('Failed to export metrics', $throwable);

            return false;
        }
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
}
