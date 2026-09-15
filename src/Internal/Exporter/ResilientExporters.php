<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Exporter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * @internal The one place a `Resilient*Exporter` is made, whoever built the exporter inside it.
 *
 * The bundle's exporter factories and an application's exporter from `sdk.<signal>.exporter`
 * both come through here, so "every exporter the pipeline holds is behind the export gate and
 * cannot throw into the application" does not depend on which of them built it.
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
