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
                'Metrics from runtimes that build a pipeline per request (PHP-FPM, FRANKENPHP_RESET_KERNEL). "disabled" exports none. "delta" exports counters and histograms as one DELTA stream per FPM child or worker thread; a cumulative backend (Prometheus, Mimir) then needs the collector\'s deltatocumulative processor, with each stream always reaching the same collector instance. Shared workers ignore this key.',
            )
            ->end()
            ->end()
            ->end()
            ->end();

        return $node;
    }
}
