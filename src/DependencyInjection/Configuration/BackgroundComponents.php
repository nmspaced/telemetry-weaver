<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Configuration of components that run off the request path.
 *
 * @internal
 */
final class BackgroundComponents
{
    /**
     * @var list<string>
     */
    private const array DEFAULT_EXCLUDED_CACHE_POOLS = [
        'cache.system',
        'cache.validator',
        'cache.serializer',
        'cache.property_info',
        'cache.messenger.restart_workers_signal',
    ];

    /** @throws \RuntimeException */
    public function messenger(): ArrayNodeDefinition
    {
        return ComponentSignal::component('messenger', 'Symfony Messenger dispatch, send and consume.');
    }

    /** @throws \RuntimeException */
    public function serializer(): ArrayNodeDefinition
    {
        return ComponentSignal::component(
            'serializer',
            'Symfony Serializer operations. Spans require an existing trace; metrics also cover operations without a parent.',
        );
    }

    /** @throws \RuntimeException */
    public function scheduler(): ArrayNodeDefinition
    {
        return ComponentSignal::component('scheduler', 'Symfony Scheduler.');
    }

    /** @throws \RuntimeException */
    public function mailer(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component('mailer', 'Outgoing mail.');

        $node
            ->children()
            ->booleanNode('record_subject')
            ->info('Record the mail subject. Off by default: subjects carry user-generated content.')
            ->defaultFalse()
            ->end()
            ->end();

        return $node;
    }

    /**
     * Worker memory and uptime, sampled at each export; metrics only.
     *
     * @throws \RuntimeException
     */
    public function runtime(): ArrayNodeDefinition
    {
        return ComponentSignal::component(
            'runtime',
            'The PHP runtime serving each worker: php.memory.usage and php.worker.uptime. In worker mode this is how a leak becomes visible.',
            traces: false,
            durations: false,
        );
    }

    /** @throws \RuntimeException */
    public function cache(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component('cache', 'Symfony Cache pools.');

        $node
            ->children()
            ->arrayNode('pools')
            ->performNoDeepMerging()
            ->info('Pool service ids to instrument. ["*"] takes every supported pool tagged cache.pool; [] takes none.')
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue(['*'])
            ->end()
            ->arrayNode('excluded_pools')
            ->performNoDeepMerging()
            ->info(
                "Pool service ids to skip. The framework's own pools are here by default: they are infrastructure, not application behaviour.",
            )
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue(self::DEFAULT_EXCLUDED_CACHE_POOLS)
            ->end()
            ->end();

        return $node;
    }
}
