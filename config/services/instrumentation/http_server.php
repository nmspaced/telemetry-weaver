<?php

declare(strict_types=1);

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationServices;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle\TelemetryFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetrics;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetricsSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurementRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateCacheWarmer;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProviderFactory;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerSpanAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerTraceResponseSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Propagation\ResponsePropagation;
use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    InstrumentationServices::register($services, 'http_server', DefaultBuckets::Http);

    $services
        ->set(RouteTemplateProvider::class)
        ->factory(RouteTemplateProviderFactory::create(...))
        ->arg('$buildDir', param('kernel.build_dir'))
        ->arg('$router', service('router.default')->nullOnInvalid())
        ->arg('$debug', param('kernel.debug'));

    $services->set(RequestRouteTemplateResolver::class)->arg(
        '$routeTemplateProvider',
        service(RouteTemplateProvider::class),
    );

    foreach (['traces', 'metrics'] as $signal) {
        $services
            ->set(\sprintf('open_telemetry.http_server.%s.request_policy', $signal), RequestPolicy::class)
            ->arg(
                '$excludedPaths',
                param(\sprintf('open_telemetry.instrumentation.http_server.%s.excluded_paths', $signal)),
            )
            ->arg('$routeTemplateResolver', service(RequestRouteTemplateResolver::class));
    }

    $services
        ->set(RouteTemplateCacheWarmer::class)
        ->arg('$router', service('router.default')->nullOnInvalid())
        ->arg('$debug', param('kernel.debug'))
        ->tag('kernel.cache_warmer');

    $services
        ->set(HttpServerMetrics::class)
        ->arg('$metrics', service('open_telemetry.http_server.metrics'))
        ->arg('$buckets', service('open_telemetry.http_server.buckets'))
        ->arg('$correlations', service(TraceCorrelationSource::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$recorder', service(DurationRecorder::class));

    $services
        ->set(RequestMeasurementRegistry::class)
        ->arg('$httpServerMetrics', service(HttpServerMetrics::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->tag('kernel.reset', ['method' => 'reset']);

    $services
        ->set(ParentContext::class)
        ->arg('$propagation', service(Propagation::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services
        ->set(RequestTraceRegistry::class)
        ->arg('$telemetry', service('open_telemetry.http_server.telemetry'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg(
            '$recordExceptionMinStatus',
            param('open_telemetry.instrumentation.http_server.record_exception_min_status'),
        )
        ->tag('kernel.reset', ['method' => 'reset']);

    $services
        ->set(ServerSpanAttributes::class)
        ->arg('$recordClientIp', param('open_telemetry.instrumentation.http_server.record_client_ip'));

    $services
        ->set(HttpServerTracingSubscriber::class)
        ->arg('$requestTraces', service(RequestTraceRegistry::class))
        ->arg('$parentContext', service(ParentContext::class))
        ->arg('$requestPolicy', service('open_telemetry.http_server.traces.request_policy'))
        ->arg('$serverSpanAttributes', service(ServerSpanAttributes::class))
        ->tag('kernel.event_subscriber');

    $services
        ->set(ServerTraceResponseSubscriber::class)
        ->arg('$responsePropagation', service(ResponsePropagation::class))
        ->arg('$requestPolicy', service('open_telemetry.http_server.traces.request_policy'))
        ->tag('kernel.event_subscriber');

    $services
        ->set(HttpServerMetricsSubscriber::class)
        ->arg('$measurements', service(RequestMeasurementRegistry::class))
        ->arg('$requestPolicy', service('open_telemetry.http_server.metrics.request_policy'))
        ->tag('kernel.event_subscriber');

    $services
        ->set(TelemetryFlushSubscriber::class)
        ->arg('$runtime', service(SymfonyRuntimeProfile::class))
        ->arg('$flusher', service(BoundaryFlush::class))
        ->tag('kernel.event_subscriber');
};
