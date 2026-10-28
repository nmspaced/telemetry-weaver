<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationServices;
use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\ListenerTelemetry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    InstrumentationServices::register($services, 'event_listeners', metrics: false);

    $services
        ->set(ListenerTelemetry::class)
        ->arg('$telemetry', service('open_telemetry.event_listeners.telemetry'))
        ->arg('$namespaces', param('open_telemetry.instrumentation.event_listeners.namespaces'));
};
