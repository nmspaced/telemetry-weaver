<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationServices;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\CacheTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    InstrumentationServices::register($services, 'cache', DefaultBuckets::Cache);

    $services
        ->set(CacheTelemetry::class)
        ->arg('$telemetry', service('open_telemetry.cache.telemetry'))
        ->arg('$buckets', service('open_telemetry.cache.buckets'));
};
