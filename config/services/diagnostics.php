<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\DiagnosticsLogger;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Both reporters share one logger, and `diagnostics.enabled` decides which object it is.
    $services
        ->set('open_telemetry.diagnostics.logger', LoggerInterface::class)
        ->factory(DiagnosticsLogger::create(...))
        ->arg('$logger', service('logger'))
        ->arg('$enabled', param('open_telemetry.diagnostics.enabled'))
        // MonologBundle rewrites the `logger` argument above to this channel's logger.
        // Inert without MonologBundle, which is the only case where there is no channel
        // to rewrite it to.
        ->tag('monolog.logger', ['channel' => DiagnosticsLogger::CHANNEL])
        // The bundle's boot() hands it to the SDK, and get() only reaches public ids.
        ->public();

    $services
        ->set(InstrumentationFailureReporter::class)
        ->arg('$logger', service('open_telemetry.diagnostics.logger'))
        ->arg('$detailedPerProcess', param('open_telemetry.diagnostics.detailed_per_process'))
        ->arg('$minIntervalSeconds', param('open_telemetry.diagnostics.min_interval_seconds'));

    $services
        ->set(ExportFailureReporter::class)
        ->arg('$logger', service('open_telemetry.diagnostics.logger'))
        ->arg('$detailedPerProcess', param('open_telemetry.diagnostics.detailed_per_process'))
        ->arg('$minIntervalSeconds', param('open_telemetry.diagnostics.min_interval_seconds'));
};
