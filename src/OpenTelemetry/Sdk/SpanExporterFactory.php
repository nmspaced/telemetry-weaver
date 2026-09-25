<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory as OtlpSpanExporterFactory;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Builds the span exporter. OTLP is built directly, because the SDK Registry cannot inject
 * the transport factory; other exporters use the Registry. `none` yields null.
 */
final readonly class SpanExporterFactory
{
    public function __construct(
        private ResilientExporters $resilient,
        private OtlpTransports $transports,
    ) {}

    /**
     * @throws \InvalidArgumentException when the configuration names more than one exporter
     * @throws \RuntimeException when the named exporter is not registered
     */
    public function create(): ?SpanExporterInterface
    {
        $exporter = ExporterName::of(Variables::OTEL_TRACES_EXPORTER);

        if ($exporter === null) {
            return null;
        }

        if ($exporter !== ExporterName::OTLP || !\class_exists(OtlpSpanExporterFactory::class)) {
            return $this->resilient->spans(Registry::spanExporterFactory($exporter)->create());
        }

        return $this->resilient->spans(
            new OtlpSpanExporterFactory($this->transports->forProtocol(OtlpProtocol::of(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)))->create(),
        );
    }
}
