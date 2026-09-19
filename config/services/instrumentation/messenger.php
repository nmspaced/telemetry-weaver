<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationServices;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessagingSystem;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerWorkerSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Messenger dispatch, send and consume.
    InstrumentationServices::register($services, 'messenger', DefaultBuckets::Messaging);

    $services
        ->set(MessengerTelemetry::class)
        ->arg('$telemetry', service('open_telemetry.messenger.telemetry'))
        ->arg('$buckets', service('open_telemetry.messenger.buckets'));

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
        ->arg('$propagation', service(Propagation::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$systems', service(MessagingSystem::class));
};
