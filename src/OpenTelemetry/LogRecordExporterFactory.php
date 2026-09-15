<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientExporters;
use OpenTelemetry\Contrib\Otlp\LogsExporterFactory as OtlpLogsExporterFactory;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Registry;

/**
 * @see SpanExporterFactory for why the Registry path is not enough for OTLP
 */
final readonly class LogRecordExporterFactory
{
    public function __construct(
        private ResilientExporters $resilient,
        private OtlpTransports $transports,
    ) {}

    /**
     * @throws \InvalidArgumentException when the configuration names more than one exporter
     * @throws \RuntimeException when the named exporter is not registered
     */
    public function create(): ?LogRecordExporterInterface
    {
        $exporter = ExporterName::of(Variables::OTEL_LOGS_EXPORTER);

        if ($exporter === null) {
            return null;
        }

        if ($exporter !== ExporterName::OTLP || !\class_exists(OtlpLogsExporterFactory::class)) {
            return $this->resilient->logs(Registry::logRecordExporterFactory($exporter)->create());
        }

        return $this->resilient->logs(
            new OtlpLogsExporterFactory($this->transports->forProtocol(OtlpProtocol::of(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL)))->create(),
        );
    }
}
