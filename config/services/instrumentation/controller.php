<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationServices;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    InstrumentationServices::register($services, 'controller', metrics: false);

    $services
        ->set(ControllerTelemetry::class)
        ->arg('$telemetry', service('open_telemetry.controller.telemetry'))
        ->arg('$activeTrace', service(ActiveTrace::class));
};
