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
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Response\AsyncResponse;

/**
 * Decorates every logical HTTP client, at the one place in the chain where the request
 * is both fully resolved and not yet retried.
 *
 * FrameworkBundle builds each client as a stack of decorators with fixed priorities —
 * throttling (5), URI template (10), scoping (15), caching (20), retry (25), the
 * profiler's own traceable client (100) — and in Symfony a *higher* decoration priority
 * is applied earlier, which puts it further *in*. Reading the chain from the outside in,
 * that is throttling, template, scoping, caching, retry, profiler, transport.
 *
 * Sitting at 18 places this decorator between scoping and caching, and both neighbours
 * are the reason for the number:
 *
 *  - inside scoping, so a scoped client's relative URL has already become an absolute
 *    one. Outside it, `$client->request('GET', 'users')` would arrive here with no host
 *    at all, and the span would be missing the attributes the conventions require.
 *  - outside caching and retry, so all the attempts a single logical request makes are
 *    one span with one duration. That is the number an application cares about: what
 *    calling this endpoint cost, not what the third of four attempts cost. A response
 *    served from the cache is inside the boundary too, which is what makes a cache that
 *    stops working visible as latency rather than as silence.
 *
 * The default client is the one exception on `base_uri`. It has no scoping decorator —
 * FrameworkBundle gives it `default_options` on the transport instead — so a relative
 * URL is still relative when it reaches this decorator and the base has to be handed
 * over for the attributes to be resolvable. Scoped clients get null, because for them
 * the question was already answered upstream.
 */
final readonly class HttpClientInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string CLIENT_TAG = 'http_client.client';

    private const string DEFAULT_CLIENT_ID = 'http_client';

    private const string TRANSPORT_ID = 'http_client.transport';

    /**
     * Between scoping (15) and caching (20); see the class docblock for why both edges.
     */
    private const int DECORATION_PRIORITY = 18;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/http-client', AsyncResponse::class)
            ->instruments('http_client');

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

    /**
     * The `base_uri` FrameworkBundle wrote into the transport's default options, if any.
     */
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
