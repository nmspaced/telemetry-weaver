<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\CacheInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedTagAwareCachePool;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Cache\Adapter\TraceableTagAwareAdapter;
use Symfony\Component\Cache\DataCollector\CacheDataCollector;
use Symfony\Component\Cache\DependencyInjection\CacheCollectorPass;
use Symfony\Component\Cache\DependencyInjection\CachePoolPass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Contracts\Cache\ItemInterface;

final class CachePoolRegistrationTest extends ContainerTestCase
{
    /**
     * @param list<string>|null $pools
     * @throws \Throwable
     */
    private function buildPools(?array $pools = null, bool $profiler = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.runtime_mode.worker', 1);
        $container->setParameter('kernel.runtime_mode.web', true);
        $container->register('logger', RecordingLogger::class);
        $container->setParameter('cache.prefix.seed', 'test');
        $container
            ->register('cache.adapter.array', ArrayAdapter::class)
            ->setAbstract(true)
            ->setArguments([0])
            ->addTag('cache.pool');
        foreach (['pool.a', 'pool.b', 'pool.untouched'] as $id) {
            $container->setDefinition($id, new ChildDefinition('cache.adapter.array')
                ->setPublic(true)
                ->addTag('cache.pool', ['default_lifetime' => 120, 'reset' => 'reset']));
        }

        $container->setAlias('pool.alias', 'pool.a')->setPublic(true);
        $container
            ->register('pool.tags', TagAwareAdapter::class)
            ->addTag('cache.pool')
            ->setArguments([new Reference('pool.untouched')])
            ->setPublic(true);

        $bundle = new TelemetryWeaverBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), [
            'traces' => ['enabled' => false],
            'instrumentation' => [
                'http_server' => ['metrics' => false],
                'cache' => $pools === null ? [] : ['pools' => $pools],
            ],
        ]);
        $bundle->build($container);
        $container->addCompilerPass(new CachePoolPass(), priority: 32);
        if ($profiler) {
            $container->register('data_collector.cache', CacheDataCollector::class)->setPublic(true);
            $container->addCompilerPass(new CacheCollectorPass(), PassConfig::TYPE_BEFORE_REMOVING);
        }

        $container->compile();

        return $container;
    }

    /** @throws \Throwable */
    #[Test]
    public function defaultConfigurationAutomaticallyDecoratesTaggedPools(): void
    {
        $container = $this->buildPools();
        foreach (['pool.a', 'pool.b', 'pool.untouched'] as $id) {
            self::assertInstanceOf(TraceableNamespacedCachePool::class, $container->get($id));
            self::assertSame([['method' => 'reset']], $container->findDefinition($id)->getTag('kernel.reset'));
        }

        self::assertSame($container->get('pool.a'), $container->get('pool.alias'));
        self::assertInstanceOf(TraceableNamespacedTagAwareCachePool::class, $container->get('pool.tags'));
    }

    /** @throws \Throwable */
    #[Test]
    public function automaticDecorationWorksWithTheSymfonyProfiler(): void
    {
        $container = $this->buildPools(profiler: true);
        $tagged = $container->get('pool.tags');
        self::assertInstanceOf(TraceableTagAwareAdapter::class, $tagged);
        self::assertInstanceOf(TraceableNamespacedTagAwareCachePool::class, $tagged->getPool());
        $pool = $container->get('pool.a');
        self::assertInstanceOf(TraceableAdapter::class, $pool);
        self::assertInstanceOf(TraceableCachePool::class, $pool->getPool());
    }

    /** @throws \Throwable */
    #[Test]
    public function selectedPoolsAreIndependentAndAliasesAreNotDecoratedTwice(): void
    {
        $container = $this->buildPools(['pool.a', 'pool.alias', 'pool.b']);
        $first = $container->get('pool.a');
        $second = $container->get('pool.b');
        self::assertInstanceOf(TraceableCachePool::class, $first);
        self::assertInstanceOf(TraceableCachePool::class, $second);
        self::assertSame($first, $container->get('pool.alias'));
        self::assertInstanceOf(ArrayAdapter::class, $container->get('pool.untouched'));
        self::assertSame('a', $first->get('key', static fn(): string => 'a'));
        self::assertSame('b', $second->get('key', static fn(): string => 'b'));
        self::assertSame('a', $first->getItem('key')->get());
    }

    /** @throws \Throwable */
    #[Test]
    public function profilerCanWrapInstrumentedPools(): void
    {
        $container = $this->buildPools(['pool.a'], true);
        $pool = $container->get('pool.a');
        self::assertInstanceOf(TraceableAdapter::class, $pool);
        self::assertInstanceOf(TraceableCachePool::class, $pool->getPool());
        self::assertSame('value', $pool->get('key', static fn(): string => 'value'));
        self::assertCount(1, $pool->getCalls());
        self::assertInstanceOf(CacheDataCollector::class, $container->get('data_collector.cache'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anEmptySelectionLeavesPoolsUnchanged(): void
    {
        self::assertInstanceOf(ArrayAdapter::class, $this->buildPools([])->get('pool.a'));
    }

    /** @throws \Throwable */
    #[Test]
    public function missingPoolsAreIgnored(): void
    {
        self::assertInstanceOf(ArrayAdapter::class, $this->buildPools(['missing'])->get('pool.a'));
    }

    /** @throws \Throwable */
    #[Test]
    public function tagInvalidationIsNeverSilentlyRemoved(): void
    {
        $pool = $this->buildPools(['pool.tags'])->get('pool.tags');
        self::assertInstanceOf(TraceableNamespacedTagAwareCachePool::class, $pool);
        self::assertSame('value', $pool->get(
            'key',
            /** @throws \Throwable */ static function (ItemInterface $item): string {
                $item->tag('products');

                return 'value';
            },
        ));
        self::assertTrue($pool->hasItem('key'));
        self::assertTrue($pool->invalidateTags(['products']));
        self::assertFalse($pool->getItem('key')->isHit());
    }

    /** @throws \Throwable */
    #[Test]
    public function namedPoolsUseTheSameLabelAsSymfonyAndAliasesAreDeduplicated(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.runtime_mode.worker', 1);
        $container->setParameter('kernel.runtime_mode.web', true);
        $container->setParameter('open_telemetry.enabled', true);
        $container->setParameter('open_telemetry.traces.enabled', true);
        $container->setParameter('open_telemetry.instrumentation.cache.traces', true);
        $container->setParameter('open_telemetry.metrics.enabled', true);
        $container->setParameter('open_telemetry.instrumentation.cache.metrics', true);
        $container->setParameter('open_telemetry.instrumentation.cache.excluded_pools', []);
        $container->setParameter('open_telemetry.instrumentation.cache.pools', ['*', 'pool.alias']);
        $container->register('pool', ArrayAdapter::class)->addTag('cache.pool', ['name' => 'products']);
        $container->setAlias('pool.alias', 'pool');
        new CacheInstrumentationCompilerPass()->process($container);

        self::assertSame('products', $container->getDefinition('pool.open_telemetry')->getArgument('$poolName'));
        self::assertFalse($container->hasDefinition('pool.alias.open_telemetry'));
        self::assertSame([['name' => 'products']], $container->getDefinition('pool')->getTag('cache.pool'));
    }

    /** @throws \Throwable */
    #[Test]
    public function discoveryAndExplicitSelectionSkipIncompatiblePools(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.runtime_mode.worker', 1);
        $container->setParameter('kernel.runtime_mode.web', true);
        $container->setParameter('open_telemetry.enabled', true);
        $container->setParameter('open_telemetry.traces.enabled', true);
        $container->setParameter('open_telemetry.instrumentation.cache.traces', true);
        $container->setParameter('open_telemetry.metrics.enabled', true);
        $container->setParameter('open_telemetry.instrumentation.cache.metrics', true);
        $container->setParameter('open_telemetry.instrumentation.cache.excluded_pools', []);
        $container->register('unsupported', \stdClass::class)->addTag('cache.pool');
        $container->setParameter('open_telemetry.instrumentation.cache.pools', ['*']);
        $pass = new CacheInstrumentationCompilerPass();
        $pass->process($container);
        self::assertFalse($container->hasDefinition('unsupported.open_telemetry'));

        $container->setParameter('open_telemetry.instrumentation.cache.pools', ['unsupported']);
        $pass->process($container);
        self::assertFalse($container->hasDefinition('unsupported.open_telemetry'));
    }
}
