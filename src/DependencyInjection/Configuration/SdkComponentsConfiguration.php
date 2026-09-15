<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Nmspaced\TelemetryWeaver\OpenTelemetry\OtlpProtocol;
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
