<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory as OtlpSpanExporterFactory;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Replaces the SDK's own ExporterFactory for one reason: it resolves the exporter
 * through the Registry, which instantiates the factory with no arguments, and the OTLP
 * factory's only injection point is a constructor argument. Going through the Registry
 * therefore makes the transport — and with it the retry behaviour that can block the
 * application — unreachable.
 *
 * Every non-OTLP exporter keeps the Registry path untouched: `memory` and `console`
 * have no transport to configure, and the container tests depend on them.
 *
 * Wrapping through `ResilientExporters` happens here rather than through a container
 * decorator, because only this factory knows whether there is anything to wrap:
 * `OTEL_TRACES_EXPORTER=none` means no exporter at all, and a decorator registered in
 * services.php would be handed the null and fail to construct. Null then travels on to
 * `SpanProcessorFactory`, which answers it with a `NoopSpanProcessor` — the signal is
 * off, and nothing downstream is built for it.
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
