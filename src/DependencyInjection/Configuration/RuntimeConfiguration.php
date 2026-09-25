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
                'Metrics from runtimes that build a pipeline per request (PHP-FPM, FRANKENPHP_RESET_KERNEL): "disabled" or "delta". Delta needs a collector deltatocumulative processor for cumulative backends. Ignored by shared workers.',
            )
            ->end()
            ->end()
            ->end()
            ->end();

        return $node;
    }
}
