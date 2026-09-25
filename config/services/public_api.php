<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\ScopedTelemetryFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeterProvider;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalTracerProvider;
use OpenTelemetry\API\Metrics\MeterProviderInterface as ApiMeterProviderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface as ApiTracerProviderInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(SpanOpener::class)
        ->arg('$tracer', service(TracerInterface::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class))
        ->arg('$instrumentationFailureReporter', service(InstrumentationFailureReporter::class));

    $services
        ->set('open_telemetry.public_api.tracer_provider', ApiTracerProviderInterface::class)
        ->factory(SignalTracerProvider::create(...))
        ->arg('$delegate', service(TracerProviderInterface::class))
        ->arg('$tracesEnabled', param('open_telemetry.traces.enabled'));

    $services
        ->set('open_telemetry.public_api.meter_provider', ApiMeterProviderInterface::class)
        ->factory(SignalMeterProvider::create(...))
        ->arg('$delegate', service(MeterProviderInterface::class))
        ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'));

    $services
        ->set(ScopedTelemetryFactory::class)
        ->arg('$tracers', service('open_telemetry.public_api.tracer_provider'))
        ->arg('$meters', service('open_telemetry.public_api.meter_provider'))
        ->arg('$contextStorage', service(ContextStorageInterface::class))
        ->arg('$recorder', service(DurationRecorder::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services->alias(TelemetryFactory::class, ScopedTelemetryFactory::class);

    $services
        ->set('open_telemetry.app.telemetry', Telemetry::class)
        ->factory([service(TelemetryFactory::class), 'scope'])
        ->arg('$name', 'app');

    $services->alias(Telemetry::class, 'open_telemetry.app.telemetry');
};
