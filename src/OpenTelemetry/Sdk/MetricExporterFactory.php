<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use OpenTelemetry\Contrib\Otlp\MetricExporterFactory as OtlpMetricExporterFactory;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Registry;

/**
 * @see SpanExporterFactory for why the Registry path is not enough for OTLP
 */
final readonly class MetricExporterFactory
{
    public function __construct(
        private ResilientExporters $resilient,
        private OtlpTransports $transports,
        private RequestMetricPolicy $requestPolicy,
    ) {}

    /**
     * @throws \InvalidArgumentException when the configuration names more than one exporter
     * @throws \RuntimeException when the named exporter is not registered
     */
    public function create(): ?MetricExporterInterface
    {
        if (!$this->requestPolicy->allows()) {
            return null;
        }

        $exporter = ExporterName::of(Variables::OTEL_METRICS_EXPORTER);

        if ($exporter === null) {
            return null;
        }

        return $this->resilient->metrics($this->exporter($exporter), $this->temporality($exporter));
    }

    /**
     * An application's own exporter (`sdk.metrics.exporter`) under the pipeline's rules: a request
     * pipeline still needs the opt-in and a writer identity, and still exports with the request
     * temporality. Outside one the exporter keeps its own temporality — the OTLP preference
     * variable belongs to the OTLP exporter the bundle builds.
     */
    public function adopt(MetricExporterInterface $exporter): ?MetricExporterInterface
    {
        if (!$this->requestPolicy->allows()) {
            return null;
        }

        return $this->resilient->metrics($exporter, $this->requestPolicy->selector());
    }

    /**
     * The OTLP preference is applied by `MetricTemporality`, not by the SDK exporter: the
     * installed factory turns `delta` into DELTA for UpDownCounters and gauges too, and
     * `lowmemory` into the synchronous streams' DELTA. Other exporters keep their own choice
     * outside a request pipeline; the variable is OTLP's.
     *
     * @param non-empty-string $name
     *
     * @throws \UnexpectedValueException for an unknown temporality preference
     */
    private function temporality(string $name): ?AggregationTemporalitySelectorInterface
    {
        $request = $this->requestPolicy->selector();
        if ($request !== null) {
            return $request;
        }

        if ($name !== ExporterName::OTLP) {
            return null;
        }

        return MetricTemporality::preferred(Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE));
    }

    /**
     * @param non-empty-string $name
     * @throws \RuntimeException
     */
    private function exporter(string $name): MetricExporterInterface
    {
        if ($name !== ExporterName::OTLP || !\class_exists(OtlpMetricExporterFactory::class)) {
            return Registry::metricExporterFactory($name)->create();
        }

        return new OtlpMetricExporterFactory($this->transports->forProtocol(OtlpProtocol::of(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL)))->create();
    }
}
