<?php

declare(strict_types=1);

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

    // Console command runs, through the console events.
    $services
        ->set('open_telemetry.console.span_opener', SpanOpenerInterface::class)
        ->factory(SignalSpanOpener::create(...))
        ->arg('$delegate', service(SpanOpener::class))
        ->arg('$tracesEnabled', param('open_telemetry.traces.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.console.traces'));

    $services
        ->set('open_telemetry.console.meter', MeterInterface::class)
        ->factory(SignalMeter::create(...))
        ->arg('$delegate', service(MeterInterface::class))
        ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.console.metrics'));

    $services
        ->set('open_telemetry.console.metrics', SafeMetrics::class)
        ->arg('$meter', service('open_telemetry.console.meter'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services
        ->set('open_telemetry.console.telemetry', DefaultTelemetry::class)
        ->arg('$opener', service('open_telemetry.console.span_opener'))
        ->arg('$instruments', service('open_telemetry.console.metrics'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class));
};
