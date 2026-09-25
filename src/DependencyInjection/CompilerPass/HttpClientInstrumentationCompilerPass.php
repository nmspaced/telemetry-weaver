<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\DecoratedService;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ClientInstrumentation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HostPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\HttpClientTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\RequestPropagation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ResponseMetadata;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\TraceableHttpClient;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Response\AsyncResponse;

/**
 * Decorates every HTTP client between scoping and caching.
 *
 * Inside scoping, so relative URLs are already absolute; outside caching and retry, so one
 * logical request is one span. The default client has no scoping decorator, so its
 * `base_uri` is passed in explicitly.
 */
final readonly class HttpClientInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string CLIENT_TAG = 'http_client.client';

    private const string DEFAULT_CLIENT_ID = 'http_client';

    private const string TRANSPORT_ID = 'http_client.transport';

    /** Between scoping (15) and caching (20). */
    private const int DECORATION_PRIORITY = 18;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/http-client', AsyncResponse::class)
            ->carriesContext('http_client');

        if ($gate->isClosed()) {
            return;
        }

        $this->registerServices($container);

        foreach (\array_keys($container->findTaggedServiceIds(self::CLIENT_TAG)) as $id) {
            $this->decorate($container, $id);
        }
    }

    private function registerServices(ContainerBuilder $container): void
    {
        $container
            ->register(HostPolicy::class, HostPolicy::class)
            ->setArgument(
                '$excludedTraceHosts',
                $container->getParameter('open_telemetry.instrumentation.http_client.traces.excluded_hosts'),
            )
            ->setArgument(
                '$excludedMetricHosts',
                $container->getParameter('open_telemetry.instrumentation.http_client.metrics.excluded_hosts'),
            );

        $container->register(ResponseMetadata::class, ResponseMetadata::class)->setArgument(
            '$reporter',
            new Reference(InstrumentationFailureReporter::class),
        );

        $container
            ->register(RequestPropagation::class, RequestPropagation::class)
            ->setArgument('$propagation', new Reference(Propagation::class))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class));

        $container
            ->register(HttpClientTelemetry::class, HttpClientTelemetry::class)
            ->setArgument('$telemetry', new Reference('open_telemetry.http_client.telemetry'))
            ->setArgument('$policy', new Reference(HostPolicy::class))
            ->setArgument('$recorder', new Reference(DurationRecorder::class))
            ->setArgument('$buckets', new Reference('open_telemetry.http_client.buckets'));

        $container
            ->register(ClientInstrumentation::class, ClientInstrumentation::class)
            ->setArgument('$telemetry', new Reference(HttpClientTelemetry::class))
            ->setArgument('$propagation', new Reference(RequestPropagation::class))
            ->setArgument('$metadata', new Reference(ResponseMetadata::class))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class));
    }

    private function decorate(ContainerBuilder $container, string $id): void
    {
        $innerId = DecoratedService::innerId($id);

        $container
            ->register(DecoratedService::id($id), TraceableHttpClient::class)
            ->setDecoratedService($id, $innerId, self::DECORATION_PRIORITY)
            ->setArgument('$client', new Reference($innerId))
            ->setArgument('$instrumentation', new Reference(ClientInstrumentation::class))
            ->setArgument('$baseUri', $id === self::DEFAULT_CLIENT_ID ? $this->defaultBaseUri($container) : null)
            ->addTag('kernel.reset', ['method' => 'reset']);
    }

    private function defaultBaseUri(ContainerBuilder $container): ?string
    {
        if (!$container->hasDefinition(self::TRANSPORT_ID)) {
            return null;
        }

        /** @var mixed $options */
        $options = $container->getDefinition(self::TRANSPORT_ID)->getArguments()[0] ?? null;

        if (!\is_array($options)) {
            return null;
        }

        /** @var mixed $baseUri */
        $baseUri = $options['base_uri'] ?? null;

        return \is_string($baseUri) ? $baseUri : null;
    }
}
