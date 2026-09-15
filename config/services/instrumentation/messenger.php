<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessagingSystem;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerWorkerSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SignalSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Messenger dispatch, send and consume.
    $services
        ->set('open_telemetry.messenger.span_opener', SpanOpenerInterface::class)
        ->factory(SignalSpanOpener::create(...))
        ->arg('$delegate', service(SpanOpener::class))
        ->arg('$tracesEnabled', param('open_telemetry.traces.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.messenger.traces'));

    $services
        ->set('open_telemetry.messenger.meter', MeterInterface::class)
        ->factory(SignalMeter::create(...))
        ->arg('$delegate', service(MeterInterface::class))
        ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.messenger.metrics'));

    $services
        ->set('open_telemetry.messenger.metrics', SafeMetrics::class)
        ->arg('$meter', service('open_telemetry.messenger.meter'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services
        ->set('open_telemetry.messenger.telemetry', DefaultTelemetry::class)
        ->arg('$opener', service('open_telemetry.messenger.span_opener'))
        ->arg('$instruments', service('open_telemetry.messenger.metrics'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class));

    $services->set(MessengerTelemetry::class)->arg('$telemetry', service('open_telemetry.messenger.telemetry'));

    // The receiver locator only exists with FrameworkBundle's Messenger; without it every
    // receiver keeps the framework fallback.
    $services->set(MessagingSystem::class)->arg('$receivers', service('messenger.receiver_locator')->nullOnInvalid());

    $services
        ->set(MessengerWorkerSubscriber::class)
        ->arg('$messengerTelemetry', service(MessengerTelemetry::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$systems', service(MessagingSystem::class))
        ->tag('kernel.event_subscriber');

    $services
        ->set(MessengerConsumption::class)
        ->arg('$messengerTelemetry', service(MessengerTelemetry::class))
        ->arg('$propagator', service(TextMapPropagatorInterface::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$systems', service(MessagingSystem::class));
};
