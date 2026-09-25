<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Propagation\ResponsePropagation;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\LogCorrelation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelLogCorrelation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelResponsePropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelTraceCorrelationSource;
use Nmspaced\TelemetryWeaver\OpenTelemetry\GlobalsRegistrar;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ExportBacklog;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LoggerProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LogRecordExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransportSettings;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ResourceInfoFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SpanExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SpanSuppressionStrategyFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TracerProviderFactory;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Propagation\ResponsePropagatorFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressionStrategy;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(ResourceInfoFactory::class)
        ->arg('$attributes', param('open_telemetry.sdk.resource_attributes'))
        ->arg('$runtime', service(SymfonyRuntimeProfile::class))
        ->arg('$requestMetrics', param('open_telemetry.runtime.request_metrics.mode'));

    $services->set(ResourceInfo::class)->factory([service(ResourceInfoFactory::class), 'create']);

    $services
        ->set(OtlpTransportSettings::class)
        ->arg('$maxRetries', param('open_telemetry.sdk.export.max_retries'))
        ->arg('$retryDelay', param('open_telemetry.sdk.export.retry_delay_ms'))
        ->arg('$headers', param('open_telemetry.sdk.exporter_otlp_headers'));

    $services
        ->set(BudgetedOtlpTransports::class)
        ->arg('$gate', service(ExportGate::class))
        ->arg('$settings', service(OtlpTransportSettings::class));

    $services->alias(OtlpTransports::class, BudgetedOtlpTransports::class);

    $services
        ->set(MeterProviderFactory::class)
        ->arg('$resourceInfo', service(ResourceInfo::class))
        ->arg('$metricExporter', service(MetricExporterInterface::class));

    $services
        ->set('open_telemetry.metrics.provider', MeterProviderInterface::class)
        ->factory([service(MeterProviderFactory::class), 'create']);

    $services
        ->set(MeterProviderInterface::class)
        ->factory([service(ProviderRegistry::class), 'metrics'])
        ->arg('$provider', service('open_telemetry.metrics.provider'));

    $services
        ->set(MeterInterface::class)
        ->factory([service(MeterProviderInterface::class), 'getMeter'])
        ->arg('$name', param('open_telemetry.scope.name'))
        ->arg('$version', param('open_telemetry.scope.version'))
        ->arg('$schemaUrl', param('open_telemetry.scope.schema_url'));

    $services
        ->set(ResilientExporters::class)
        ->arg('$reporter', service(ExportFailureReporter::class))
        ->arg('$gate', service(ExportGate::class));

    $services
        ->set(MetricExporterFactory::class)
        ->arg('$resilient', service(ResilientExporters::class))
        ->arg('$transports', service(OtlpTransports::class))
        ->arg('$requestPolicy', service(RequestMetricPolicy::class));

    $services->set(MetricExporterInterface::class)->factory([service(MetricExporterFactory::class), 'create']);

    $services->set('open_telemetry.traces.backlog', ExportBacklog::class)->factory(ExportBacklog::spans(...));

    $services
        ->set(TracerProviderFactory::class)
        ->arg('$resourceInfo', service(ResourceInfo::class))
        ->arg('$meterProvider', service(MeterProviderInterface::class))
        ->arg('$spanExporter', service(SpanExporterInterface::class))
        ->arg(
            '$spanSuppressionStrategy',
            // @mago-expect analysis:experimental-usage — used deliberately
            service(SpanSuppressionStrategy::class),
        );

    $services
        ->set('open_telemetry.traces.provider', TracerProviderInterface::class)
        ->factory([service(TracerProviderFactory::class), 'create'])
        ->arg('$backlog', service('open_telemetry.traces.backlog'));

    $services
        ->set(TracerProviderInterface::class)
        ->factory([service(ProviderRegistry::class), 'traces'])
        ->arg('$provider', service('open_telemetry.traces.provider'))
        ->arg('$backlog', service('open_telemetry.traces.backlog'));

    $services
        ->set(TracerInterface::class)
        ->factory([service(TracerProviderInterface::class), 'getTracer'])
        ->arg('$name', param('open_telemetry.scope.name'))
        ->arg('$version', param('open_telemetry.scope.version'))
        ->arg('$schemaUrl', param('open_telemetry.scope.schema_url'));

    // @mago-expect analysis:experimental-usage — used deliberately
    $services->set(SpanSuppressionStrategy::class)->factory(SpanSuppressionStrategyFactory::create(...));

    $services
        ->set(SpanExporterFactory::class)
        ->arg('$resilient', service(ResilientExporters::class))
        ->arg('$transports', service(OtlpTransports::class));

    $services->set(SpanExporterInterface::class)->factory([service(SpanExporterFactory::class), 'create']);

    $services
        ->set(GlobalsRegistrar::class)
        ->arg('$tracerProvider', service_closure(TracerProviderInterface::class))
        ->arg('$meterProvider', service_closure(MeterProviderInterface::class))
        ->arg('$loggerProvider', service_closure('open_telemetry.logger_provider'))
        ->arg('$propagator', service_closure(TextMapPropagatorInterface::class))
        // @mago-expect analysis:experimental-usage
        ->arg('$responsePropagator', service_closure(ResponsePropagatorInterface::class));

    $services->alias(GlobalsRegistrar::REGISTRAR_ID, GlobalsRegistrar::class)->public();

    $services->set(ContextStorageInterface::class)->factory(Context::storage(...));

    $services
        ->set(TraceCorrelationSource::class, OtelTraceCorrelationSource::class)
        ->arg('$contextStorage', service(ContextStorageInterface::class));

    $services->set(DurationRecorder::class, OtelDurationRecorder::class);

    $services->set(BaggageReader::class, OtelBaggageReader::class);

    $services
        ->set(Propagation::class, OtelPropagation::class)
        ->arg('$propagator', service(TextMapPropagatorInterface::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services
        ->set(ActiveTrace::class, OtelActiveTrace::class)
        ->arg('$contextStorage', service(ContextStorageInterface::class));

    $services->set(LogCorrelation::class, OtelLogCorrelation::class);

    $services->set(PropagatorFactory::class);

    // @mago-expect analysis:experimental-usage — used deliberately
    $services->set(ResponsePropagatorFactory::class);

    $services
        // @mago-expect analysis:experimental-usage
        ->set(ResponsePropagatorInterface::class)
        // @mago-expect analysis:experimental-usage
        ->factory([service(ResponsePropagatorFactory::class), 'create']);

    $services
        ->set(ResponsePropagation::class, OtelResponsePropagation::class)
        // @mago-expect analysis:experimental-usage
        ->arg('$propagator', service(ResponsePropagatorInterface::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services->set(TextMapPropagatorInterface::class)->factory([service(PropagatorFactory::class), 'create']);

    $services->set('open_telemetry.logs.backlog', ExportBacklog::class)->factory(ExportBacklog::logRecords(...));

    $services
        ->set(LoggerProviderFactory::class)
        ->arg('$resourceInfo', service(ResourceInfo::class))
        ->arg('$logRecordExporter', service(LogRecordExporterInterface::class));

    $services
        ->set('open_telemetry.logs.provider', LoggerProviderInterface::class)
        ->factory([service(LoggerProviderFactory::class), 'create'])
        ->arg('$backlog', service('open_telemetry.logs.backlog'));

    $services
        ->set('open_telemetry.logger_provider', LoggerProviderInterface::class)
        ->factory([service(ProviderRegistry::class), 'logs'])
        ->arg('$provider', service('open_telemetry.logs.provider'))
        ->arg('$backlog', service('open_telemetry.logs.backlog'));

    $services
        ->set(LogRecordExporterFactory::class)
        ->arg('$resilient', service(ResilientExporters::class))
        ->arg('$transports', service(OtlpTransports::class));

    $services->set(LogRecordExporterInterface::class)->factory([service(LogRecordExporterFactory::class), 'create']);
};
