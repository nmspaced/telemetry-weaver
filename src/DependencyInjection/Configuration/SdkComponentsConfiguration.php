<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpProtocol;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * The `sdk` keys that name an application's own link of the export pipeline.
 *
 * Each value is a service id. None duplicates an OTEL_* variable: the variables choose among the
 * implementations the SDK knows, these hand the pipeline one it does not. The ids are checked by
 * `SdkComponentsCompilerPass` rather than here, because only the compiled container knows
 * whether a service exists, what it implements, and which keys it makes meaningless.
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
     * The traces signal, plus the three trace decisions no OTEL_* variable can express.
     *
     * They live on `traces` rather than in {@see self::signal()} because there is nothing
     * equivalent for metrics or logs: a meter has no sampler, and a log record no trace id
     * of its own to generate.
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
            ->info(
                'Service implementing the SDK SamplerInterface, used instead of OTEL_TRACES_SAMPLER. For a decision the variable cannot express: per route, per tenant, per anything the request carries.',
            )
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
     * The metrics signal, plus the views the SDK has no other way to register.
     *
     * @throws \RuntimeException
     */
    public static function metrics(): ArrayNodeDefinition
    {
        $views = new ArrayNodeDefinition('views');
        $views
            ->info(
                'Services of type MetricView, each pairing a selection criteria with a view template. For what instrumentation.<component>.duration_buckets cannot reach: an instrument the bundle did not create, or an attribute key whose cardinality has to be cut at the source.',
            )
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
                'Service implementing the SDK provider interface of this signal. The bundle still registers it for the boundary flush and shutdown and hands it to Globals; nothing inside it is wrapped.',
            )
            ->defaultNull()
            ->end()
            ->scalarNode('exporter')
            ->info(
                'Service implementing the SDK exporter interface of this signal, used instead of OTEL_<SIGNAL>_EXPORTER. Still wrapped by the resilient exporter and the export gate. Not allowed next to a provider.',
            )
            ->defaultNull()
            ->end()
            ->end();

        return $node;
    }
}
