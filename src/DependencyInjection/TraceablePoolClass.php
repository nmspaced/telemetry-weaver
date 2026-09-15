<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedTagAwareCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableTagAwareCachePool;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Picks the decorator that matches what a pool can do.
 *
 * Four of them exist because a decorator must not narrow its subject: wrapping a
 * tag-aware pool in a plain decorator would silently remove tag invalidation, and the
 * application would only find out when a tag stopped clearing anything.
 *
 * `NamespacedPoolInterface` is checked for existence rather than assumed: it arrived in
 * a later Symfony Cache release than the bundle's floor.
 */
final readonly class TraceablePoolClass
{
    /**
     * @param class-string $pool
     *
     * @return class-string<TraceableCachePool>
     */
    public static function for(string $pool): string
    {
        $tagAware = \is_a($pool, TagAwareAdapterInterface::class, true);
        $namespaced =
            \interface_exists(NamespacedPoolInterface::class) && \is_a($pool, NamespacedPoolInterface::class, true);

        return match (true) {
            $tagAware && $namespaced => TraceableNamespacedTagAwareCachePool::class,
            $tagAware => TraceableTagAwareCachePool::class,
            $namespaced => TraceableNamespacedCachePool::class,
            default => TraceableCachePool::class,
        };
    }
}
