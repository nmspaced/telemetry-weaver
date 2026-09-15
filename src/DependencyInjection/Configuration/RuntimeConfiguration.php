<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

final class RuntimeConfiguration
{
    public static function node(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('runtime');
        $node
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('request_metrics')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('mode')
            ->values(['disabled', 'delta'])
            ->defaultValue('disabled')
            ->info(
                'Short-lived HTTP pipelines: opt in to delta counters and histograms. Worker metrics keep SDK defaults.',
            )
            ->end()
            ->end()
            ->end()
            ->end();

        return $node;
    }
}
