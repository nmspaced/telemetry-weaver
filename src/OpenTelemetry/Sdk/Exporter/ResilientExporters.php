<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * @internal
 *
 * Wraps every exporter, bundle-built or configured, in a `Resilient*Exporter`.
 */
final readonly class ResilientExporters
{
    public function __construct(
        private ExportFailureReporter $reporter,
        private ExportGate $gate,
    ) {}

    public function spans(SpanExporterInterface $exporter): ResilientTracesExporter
    {
        return new ResilientTracesExporter($exporter, $this->reporter, $this->gate);
    }

    public function metrics(
        MetricExporterInterface $exporter,
        ?AggregationTemporalitySelectorInterface $selector = null,
    ): ResilientMetricsExporter {
        return new ResilientMetricsExporter($exporter, $this->reporter, $this->gate, $selector);
    }

    public function logs(LogRecordExporterInterface $exporter): ResilientLogsExporter
    {
        return new ResilientLogsExporter($exporter, $this->reporter, $this->gate);
    }
}
