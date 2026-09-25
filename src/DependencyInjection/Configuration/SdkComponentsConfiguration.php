<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpProtocol;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * The `sdk` keys that name an application's own service for a link of the export pipeline.
 *
 * The ids are validated by `SdkComponentsCompilerPass`, where the services are known.
 */
final class SdkComponentsConfiguration
{
    private function __construct() {}

    /** @throws \RuntimeException */
    public static function otlp(): ArrayNodeDefinition
    {
        $factories = new ArrayNodeDefinition('transport_factories');
        $factories
            ->addDefaultsIfNotSet()
            ->info(
                "Services implementing the SDK's TransportFactoryInterface, per protocol family: grpc, and http for http/protobuf, http/json and http/ndjson. A named family loses the bundle's flush budget, retry override and exporter_otlp_headers; an unnamed one keeps the bundle's transport.",
            );
        $children = $factories->children();
        foreach (OtlpProtocol::FAMILIES as $family) {
            $children->scalarNode($family)->defaultNull()->end();
        }

        $node = new ArrayNodeDefinition('otlp');
        $node
            ->addDefaultsIfNotSet()
            ->info('Replaces the OTLP transport layer the bundle builds.')
            ->append($factories);

        return $node;
    }

    /**
     * The traces signal, plus its sampler, id generator and span processors.
     *
     * @throws \RuntimeException
     */
    public static function traces(): ArrayNodeDefinition
    {
        $processors = new ArrayNodeDefinition('span_processors');
        $processors
            ->info(
                "Services implementing the SDK's SpanProcessorInterface, added in front of the bundle's own so that a processor editing a span as it ends sees it before it is queued. Added to the pipeline, never instead of it.",
            )
            ->scalarPrototype()
            ->end()
            ->defaultValue([]);

        $node = self::signal('traces');
        $node
            ->children()
            ->scalarNode('sampler')
            ->info('Service implementing the SDK SamplerInterface, used instead of OTEL_TRACES_SAMPLER.')
            ->defaultNull()
            ->end()
            ->scalarNode('id_generator')
            ->info(
                "Service implementing the SDK IdGeneratorInterface. Needed by backends that read structure out of the trace id — AWS X-Ray requires the id's first bytes to be the start timestamp.",
            )
            ->defaultNull()
            ->end()
            ->end();

        return $node->append($processors);
    }

    /**
     * The metrics signal, plus its views.
     *
     * @throws \RuntimeException
     */
    public static function metrics(): ArrayNodeDefinition
    {
        $views = new ArrayNodeDefinition('views');
        $views
            ->info('Services of type MetricView, each pairing a selection criteria with a view template.')
            ->scalarPrototype()
            ->end()
            ->defaultValue([]);

        return self::signal('metrics')->append($views);
    }

    /** @throws \RuntimeException */
    public static function signal(string $name): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->addDefaultsIfNotSet()
            ->info("Replaces a link of this signal's export pipeline.")
            ->children()
            ->scalarNode('provider')
            ->info(
                'Service implementing the SDK provider interface of this signal. Still flushed and shut down by the bundle; nothing inside it is wrapped.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('exporter')
            ->info(
                'Service implementing the SDK exporter interface of this signal, used instead of OTEL_<SIGNAL>_EXPORTER. Not allowed next to a provider.',
            )
            ->defaultNull()
            ->end()
            ->end();

        return $node;
    }
}
