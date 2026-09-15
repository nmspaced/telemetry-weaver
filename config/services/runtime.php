<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ProviderRegistry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\RequestMetricPolicy;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services
        ->set(SymfonyRuntimeProfile::class)
        ->factory(SymfonyRuntimeProfile::fromKernel(...))
        ->arg('$workerMode', param('kernel.runtime_mode.worker'))
        ->arg('$web', param('kernel.runtime_mode.web'));

    $services
        ->set(FlushBudget::class)
        ->arg('$timeoutMilliseconds', param('open_telemetry.sdk.export.flush_timeout_ms'))
        ->arg('$failureCooldownMilliseconds', param('open_telemetry.sdk.export.failure_cooldown_ms'));

    $services->set(ExportGate::class)->factory(ExportGate::forBudget(...))
        ->arg('$budget', service(FlushBudget::class));

    $services
        ->set(ProviderRegistry::class)
        ->arg('$gate', service(ExportGate::class))
        ->arg('$failures', service(ExportFailureReporter::class))
        ->arg('$failureCooldownMilliseconds', param('open_telemetry.sdk.export.failure_cooldown_ms'))
        ->arg('$metricIntervalMilliseconds', param('open_telemetry.metrics.flush_interval_ms'));

    $services
        ->set(TelemetryFlusher::class)
        ->arg('$providers', service(ProviderRegistry::class))
        ->arg('$budget', service(FlushBudget::class))
        ->arg('$failures', service(ExportFailureReporter::class))
        ->arg('$runtime', service(SymfonyRuntimeProfile::class));

    $services
        ->set(RequestMetricPolicy::class)
        ->factory(RequestMetricPolicy::forRuntime(...))
        ->arg('$runtime', service(SymfonyRuntimeProfile::class))
        ->arg('$mode', param('open_telemetry.runtime.request_metrics.mode'))
        ->arg('$failures', service(ExportFailureReporter::class))
        ->arg('$resource', service(ResourceInfo::class));
};
