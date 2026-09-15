<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ProviderRegistry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\GlobalsRegistrar;
use Nmspaced\TelemetryWeaver\OpenTelemetry\LoggerProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\LogRecordExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpTransportSettings;
use Nmspaced\TelemetryWeaver\OpenTelemetry\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\ResourceInfoFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SpanExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SpanSuppressionStrategyFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\TracerProviderFactory;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
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

    $services->set(ResourceInfoFactory::class)->arg('$attributes', param('open_telemetry.sdk.resource_attributes'))
        ->arg('$runtime', service(SymfonyRuntimeProfile::class));

    $services->set(ResourceInfo::class)->factory([service(ResourceInfoFactory::class), 'create']);

    $services
        ->set(OtlpTransportSettings::class)
        ->arg('$maxRetries', param('open_telemetry.sdk.export.max_retries'))
        ->arg('$retryDelay', param('open_telemetry.sdk.export.retry_delay_ms'))
        ->arg('$headers', param('open_telemetry.sdk.exporter_otlp_headers'));

    // SdkComponentsCompilerPass points this alias at CustomOtlpTransports, in front of these,
    // when sdk.otlp.transport_factories names a factory.
    $services
        ->set(BudgetedOtlpTransports::class)
        ->arg('$gate', service(ExportGate::class))
        ->arg('$settings', service(OtlpTransportSettings::class));

    $services->alias(OtlpTransports::class, BudgetedOtlpTransports::class);

    $services
        ->set(MeterProviderFactory::class)
        ->arg('$resourceInfo', service(ResourceInfo::class))
        ->arg('$metricExporter', service(MetricExporterInterface::class));

    // Every provider is registered here rather than in its factory, so a provider an application
    // configures (SdkComponentsCompilerPass aliases open_telemetry.<signal>.provider to it) is
    // flushed and shut down exactly like the one the bundle builds.
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

    $services
        ->set(TracerProviderFactory::class)
        ->arg('$resourceInfo', service(ResourceInfo::class))
        ->arg('$meterProvider', service(MeterProviderInterface::class))
        ->arg('$spanExporter', service(SpanExporterInterface::class))
        ->arg(
            '$spanSuppressionStrategy',
            // @mago-expect analysis:experimental-usage — span suppression is experimental upstream and the bundle depends on it deliberately
            service(SpanSuppressionStrategy::class),
        );

    $services
        ->set('open_telemetry.traces.provider', TracerProviderInterface::class)
        ->factory([service(TracerProviderFactory::class), 'create']);

    $services
        ->set(TracerProviderInterface::class)
        ->factory([service(ProviderRegistry::class), 'traces'])
        ->arg('$provider', service('open_telemetry.traces.provider'));

    $services
        ->set(TracerInterface::class)
        ->factory([service(TracerProviderInterface::class), 'getTracer'])
        ->arg('$name', param('open_telemetry.scope.name'))
        ->arg('$version', param('open_telemetry.scope.version'))
        ->arg('$schemaUrl', param('open_telemetry.scope.schema_url'));

    // @mago-expect analysis:experimental-usage — span suppression is experimental upstream and the bundle depends on it deliberately
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
        ->arg('$propagator', service_closure(TextMapPropagatorInterface::class));

    // The bundle's boot() has to reach this one, and get() only works on public
    // ids. A public alias keeps the class itself private: what the container
    // exposes is one namespaced string, not an autowirable FQCN that looks like
    // an invitation.
    $services->alias(GlobalsRegistrar::REGISTRAR_ID, GlobalsRegistrar::class)->public();

    $services->set(ContextStorageInterface::class)->factory(Context::storage(...));

    $services->set(PropagatorFactory::class);

    $services->set(TextMapPropagatorInterface::class)->factory([service(PropagatorFactory::class), 'create']);

    // TraceContextProcessor and OtelLogHandler are registered by
    // MonologInstrumentationCompilerPass: both are conditional, and a definition made
    // here could only be removed there, which is how logs.correlation came to mean
    // nothing at all.

    $services
        ->set(LoggerProviderFactory::class)
        ->arg('$resourceInfo', service(ResourceInfo::class))
        ->arg('$logRecordExporter', service(LogRecordExporterInterface::class));

    $services
        ->set('open_telemetry.logs.provider', LoggerProviderInterface::class)
        ->factory([service(LoggerProviderFactory::class), 'create']);

    $services
        ->set('open_telemetry.logger_provider', LoggerProviderInterface::class)
        ->factory([service(ProviderRegistry::class), 'logs'])
        ->arg('$provider', service('open_telemetry.logs.provider'));

    $services
        ->set(LogRecordExporterFactory::class)
        ->arg('$resilient', service(ResilientExporters::class))
        ->arg('$transports', service(OtlpTransports::class));

    $services->set(LogRecordExporterInterface::class)->factory([service(LogRecordExporterFactory::class), 'create']);
};
