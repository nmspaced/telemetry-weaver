<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Internal\Operation\DisabledTelemetryFactory;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoActiveTrace;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->set(DisabledTelemetryFactory::class);

    $services->alias(TelemetryFactory::class, DisabledTelemetryFactory::class);

    $services
        ->set('open_telemetry.app.telemetry', Telemetry::class)
        ->factory([service(TelemetryFactory::class), 'scope'])
        ->arg('$name', 'app');

    $services->alias(Telemetry::class, 'open_telemetry.app.telemetry');

    $services->set(ActiveTrace::class, NoActiveTrace::class);
};
