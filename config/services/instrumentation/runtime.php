<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Instrumentation\Runtime\ProcessMetrics;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeter;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The PHP process itself. Metrics only: no span opener and no facade, because there
    // is no operation to trace — an observable gauge is read, never started or finished.
    $services
        ->set('open_telemetry.runtime.meter', MeterInterface::class)
        ->factory(SignalMeter::create(...))
        ->arg('$delegate', service(MeterInterface::class))
        ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.runtime.metrics'));

    $services
        ->set('open_telemetry.runtime.metrics', SafeMetrics::class)
        ->arg('$meter', service('open_telemetry.runtime.meter'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    // Attached to the first incoming request rather than to boot(): a process that only
    // ever runs one console command has no memory curve worth reporting, and registering
    // at boot would give every `cache:clear` a sample of its own two-second lifetime.
    // MessengerInstrumentationCompilerPass adds the worker-loop half of the same idea.
    $services
        ->set(ProcessMetrics::class)
        ->arg('$runtime', service(SymfonyRuntimeProfile::class))
        ->arg('$metrics', service('open_telemetry.runtime.metrics'))
        ->tag('kernel.event_listener', [
            'event' => KernelEvents::REQUEST,
            'method' => 'register',
            'priority' => 8192,
        ]);
};
