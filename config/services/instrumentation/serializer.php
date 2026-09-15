<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Instrumentation\Serializer\SerializerTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SignalSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Serializer operations, through the traceable serializers.
    $services
        ->set('open_telemetry.serializer.span_opener', SpanOpenerInterface::class)
        ->factory(SignalSpanOpener::create(...))
        ->arg('$delegate', service(SpanOpener::class))
        ->arg('$tracesEnabled', param('open_telemetry.traces.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.serializer.traces'));

    $services
        ->set('open_telemetry.serializer.meter', MeterInterface::class)
        ->factory(SignalMeter::create(...))
        ->arg('$delegate', service(MeterInterface::class))
        ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.serializer.metrics'));

    $services
        ->set('open_telemetry.serializer.metrics', SafeMetrics::class)
        ->arg('$meter', service('open_telemetry.serializer.meter'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services
        ->set('open_telemetry.serializer.telemetry', DefaultTelemetry::class)
        ->arg('$opener', service('open_telemetry.serializer.span_opener'))
        ->arg('$instruments', service('open_telemetry.serializer.metrics'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class));

    $services->set(SerializerTelemetry::class)->arg('$telemetry', service('open_telemetry.serializer.telemetry'));
};
