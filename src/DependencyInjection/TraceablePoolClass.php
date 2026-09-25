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
 * Picks the cache decorator matching what a pool implements, so no capability such as tag
 * invalidation is lost.
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
