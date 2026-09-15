<?php

declare(strict_types=1);

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
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
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

    // Incoming requests: the HTTP server subscribers.
    $services
        ->set('open_telemetry.http_server.span_opener', SpanOpenerInterface::class)
        ->factory(SignalSpanOpener::create(...))
        ->arg('$delegate', service(SpanOpener::class))
        ->arg('$tracesEnabled', param('open_telemetry.traces.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.http_server.traces'));

    $services
        ->set('open_telemetry.http_server.meter', MeterInterface::class)
        ->factory(SignalMeter::create(...))
        ->arg('$delegate', service(MeterInterface::class))
        ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'))
        ->arg('$signalEnabled', param('open_telemetry.instrumentation.http_server.metrics'));

    $services
        ->set('open_telemetry.http_server.metrics', SafeMetrics::class)
        ->arg('$meter', service('open_telemetry.http_server.meter'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class));

    $services
        ->set('open_telemetry.http_server.telemetry', DefaultTelemetry::class)
        ->arg('$opener', service('open_telemetry.http_server.span_opener'))
        ->arg('$instruments', service('open_telemetry.http_server.metrics'))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class));

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

    // One policy per signal, because the exclusion list is per signal: /health is noise
    // in a trace and load in a metric, and the configuration tree says so with
    // `traces.excluded_paths` / `metrics.excluded_paths`. A single shared policy was how
    // both lists came to be ignored.
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

    $services->set(HttpServerMetrics::class)->arg('$metrics', service('open_telemetry.http_server.metrics'));

    $services
        ->set(RequestMeasurementRegistry::class)
        ->arg('$httpServerMetrics', service(HttpServerMetrics::class))
        ->arg('$reporter', service(InstrumentationFailureReporter::class))
        ->tag('kernel.reset', ['method' => 'reset']);

    $services
        ->set(ParentContext::class)
        ->arg('$propagator', service(TextMapPropagatorInterface::class))
        ->arg('$contextStorage', service(ContextStorageInterface::class));

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
        ->set(HttpServerMetricsSubscriber::class)
        ->arg('$measurements', service(RequestMeasurementRegistry::class))
        ->arg('$requestPolicy', service('open_telemetry.http_server.metrics.request_policy'))
        ->tag('kernel.event_subscriber');

    $services
        ->set(TelemetryFlushSubscriber::class)
        ->arg('$runtime', service(SymfonyRuntimeProfile::class))
        ->arg('$flusher', service(TelemetryFlusher::class))
        ->tag('kernel.event_subscriber');
};
