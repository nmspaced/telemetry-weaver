<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationServices;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    InstrumentationServices::register($services, 'mailer', DefaultBuckets::Mail);
};
