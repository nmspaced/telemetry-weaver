<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\CacheTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedTagAwareCachePool;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableTagAwareCachePool;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\BadMethodCallException;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

// @mago-expect lint:too-many-methods — exercises the complete decorator contract and telemetry failures
final class TraceableCachePoolTest extends TelemetryTestCase
{
    private InMemoryExporter $metrics;

    private MeterProviderInterface $meters;

    private FrozenClock $clock;

    private CacheTelemetry $cacheTelemetry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new InMemoryExporter();
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->metrics))->build();
        $this->clock = new FrozenClock();
        $this->cacheTelemetry = $this->telemetryFor($this->meters->getMeter('test'));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->meters->shutdown();
        parent::tearDown();
    }

    /** Named lookup: every registered instrument is collected, recorded or not. */
    private function metric(string $name): Metric
    {
        $this->meters->forceFlush();

        foreach ($this->metrics->collect(true) as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        self::fail('Missing metric: ' . $name);
    }

    private function telemetryFor(MeterInterface $meter): CacheTelemetry
    {
        return new CacheTelemetry(TelemetryFactory::create($meter, $this->spans, $this->reporter, $this->clock));
    }

    private function pool(?AdapterInterface $delegate = null, ?CacheTelemetry $telemetry = null): TraceableCachePool
    {
        return new TraceableCachePool(
            $delegate ?? new ArrayAdapter(),
            $telemetry ?? $this->cacheTelemetry,
            'cache.test',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function readsPreserveItemsAndRecordKeysAndHits(): void
    {
        $delegate = new ArrayAdapter();
        $delegate->save($delegate->getItem('secret')->set('value'));

        $pool = $this->pool($delegate);

        self::assertSame('value', $pool->getItem('secret')->get());
        self::assertFalse($pool->getItem('missing')->isHit());
        self::assertFalse($pool->hasItem('missing'));
        self::assertSame(['cache.getItem', 'cache.getItem', 'cache.hasItem'], $this->exportedNames());
        self::assertSame(
            [
                'cache.pool.name' => 'cache.test',
                'cache.operation.name' => 'getItem',
                'cache.key' => 'secret',
                'cache.hit' => true,
            ],
            $this->exportedSpan()->getAttributes()->toArray(),
        );
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan(2)->getStatus()->getCode());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function callbackSaveFlagFalseValueAndMetadataArePreserved(): void
    {
        $pool = $this->pool();
        $calls = 0;
        $callback = static function (ItemInterface $item, bool &$save) use (&$calls): bool {
            ++$calls;
            $save = false;
            $item->expiresAfter(30);

            return false;
        };
        $metadata = null;
        self::assertFalse($pool->get('key', $callback, 0.0, $metadata));
        self::assertFalse($pool->get('key', $callback, 0.0, $metadata));
        // @mago-expect analysis:impossible-type-comparison — the delegate invokes the callback once per miss
        self::assertSame(2, $calls);
        self::assertIsArray($metadata);
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan()->getStatus()->getCode());
        self::assertFalse($this->exportedSpan()->getAttributes()->get('cache.hit'));
    }

    /** @throws \Throwable */
    #[Test]
    public function cacheHitsDoNotInvokeTheCallbackAndBetaIsForwarded(): void
    {
        $pool = $this->pool();
        $calls = 0;
        $callback = static function () use (&$calls): int {
            return ++$calls;
        };
        self::assertSame(1, $pool->get('key', $callback, 0.0));
        self::assertSame(1, $pool->get('key', $callback, 0.0));
        self::assertTrue($this->exportedSpan(1)->getAttributes()->get('cache.hit'));
        self::assertSame(2, $pool->get('key', $callback, \INF));
    }

    /** @throws \Throwable */
    #[Test]
    public function deferredWritesAndDeletesAreForwarded(): void
    {
        $delegate = new ArrayAdapter();
        $pool = $this->pool($delegate);
        $item = $delegate->getItem('key')->set('value');
        self::assertTrue($pool->saveDeferred($item));
        self::assertTrue($pool->commit());
        self::assertTrue($delegate->hasItem('key'));
        self::assertTrue($pool->delete('key'));
        self::assertFalse($delegate->hasItem('key'));
        self::assertTrue($pool->save($item));
        self::assertTrue($pool->deleteItem('key'));
        self::assertTrue($pool->save($item));
        self::assertTrue($pool->deleteItems(['key']));
        self::assertTrue($pool->clear());
    }

    /**
     * A pool that answers false is working: Symfony returns false for a write it could
     * not perform, and the span says only what happened. Only a throw errors the span.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aFalseWriteResultIsPreservedAndIsNotAnError(): void
    {
        $delegate = $this->createMock(AdapterInterface::class);
        $delegate->expects(self::once())->method('commit')->willReturn(false);

        self::assertFalse($this->pool($delegate)->commit());

        self::assertSame(['cache.commit'], $this->exportedNames());
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan()->getStatus()->getCode());
        self::assertSame([], $this->exportedSpan()->getEvents());
    }

    /** @throws \Throwable */
    #[Test]
    public function callbackExceptionsAreRethrownUnchangedAndScopesAreReleased(): void
    {
        $error = new \RuntimeException('computation failed');
        try {
            $this->pool()->get(
                'key',
                /** @throws \RuntimeException */ static function () use ($error): never {
                    throw $error;
                },
            );
            // @mago-expect analysis:unevaluated-code — detects accidental swallowing of the callback exception
            self::fail('The exception must propagate.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->exportedSpan()->getStatus()->getCode());
        self::assertNull(Context::storage()->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function batchReadsStayLazyAndReleaseContextBeforeYielding(): void
    {
        $item = new ArrayAdapter()->getItem('key');
        $steps = 0;
        $delegate = $this->createMock(AdapterInterface::class);
        $delegate
            ->expects(self::once())
            ->method('getItems')
            ->with(['key'])
            ->willReturnCallback(
                /** @return \Generator<string, CacheItem> */
                static function () use ($item, &$steps): \Generator {
                    ++$steps;
                    yield 'key' => $item;
                    ++$steps;
                },
            );
        $items = $this->pool($delegate)->getItems(['key']);
        self::assertSame(0, $steps);
        // @mago-expect lint:loop-does-not-iterate — deliberately abandons a partially consumed batch
        foreach ($items as $key => $found) {
            self::assertSame('key', $key);
            self::assertSame($item, $found);
            // @mago-expect analysis:impossible-type-comparison — the generator was advanced once
            self::assertSame(1, $steps);
            self::assertNull(Context::storage()->scope());
            break;
        }

        unset($items);
        // @mago-expect analysis:impossible-type-comparison — the generator was advanced once
        self::assertSame(1, $steps);
        self::assertSame(['cache.getItems'], $this->exportedNames());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function lazyIteratorExceptionsArePreservedWithoutLeavingAScope(): void
    {
        $error = new \RuntimeException('read failed');
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate
            ->method('getItems')
            ->willReturnCallback(
                /**
                 * @return \Generator<string, CacheItem>
                 * @throws \RuntimeException
                 */
                static function () use ($error): \Generator {
                    yield from [];
                    throw $error;
                },
            );
        try {
            \iterator_to_array($this->pool($delegate)->getItems(['key']));
            self::fail('The iterator exception must propagate.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertSame(['cache.getItems'], $this->exportedNames());
        self::assertNull(Context::storage()->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function namespacesAreIsolatedAndKeepTheSameMetricLabels(): void
    {
        $pool = new TraceableNamespacedCachePool(new ArrayAdapter(), $this->cacheTelemetry, 'cache.test');
        $child = $pool->withSubNamespace('tenant');
        self::assertNotSame($pool, $child);
        self::assertSame('root', $pool->get('key', static fn(): string => 'root'));
        self::assertSame('child', $child->get('key', static fn(): string => 'child'));
        self::assertSame('root', $pool->getItem('key')->get());
        self::assertSame('cache.test', $this->exportedSpan(1)->getAttributes()->get('cache.pool.name'));
    }

    /** @throws \Throwable */
    #[Test]
    public function unsupportedOptionalInterfacesHaveSymfonyFallbacks(): void
    {
        $pool = $this->pool($this->createStub(AdapterInterface::class));
        self::assertFalse($pool->prune());
        $pool->reset();
        self::assertNotInstanceOf(NamespacedPoolInterface::class, $pool);
        self::assertNotInstanceOf(TagAwareAdapterInterface::class, $pool);
        $this->expectException(BadMethodCallException::class);
        $pool->get('unsupported', static fn(): string => 'value');
    }

    /** @throws \Throwable */
    #[Test]
    public function theDurationReachesTheExporterInSeconds(): void
    {
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate
            ->method('commit')
            ->willReturnCallback(function (): bool {
                $this->clock->advanceNanoseconds(250_000);

                return true;
            });
        self::assertTrue($this->pool($delegate)->commit());

        $metric = $this->metric('cache.operation.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        self::assertSame('s', $metric->unit);
        self::assertCount(1, $metric->data->dataPoints);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame(1, $point->count);
            self::assertSame(0.000_25, $point->sum);
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function brokenMetricsDoNotChangeTheCacheResultOrRepeatTheCall(): void
    {
        $histogram = $this->createStub(HistogramInterface::class);
        $histogram->method('record')->willThrowException(new \RuntimeException('metrics unavailable'));
        $meter = $this->createStub(MeterInterface::class);
        $meter->method('createHistogram')->willReturn($histogram);
        $meter->method('createCounter')->willReturn($this->createStub(CounterInterface::class));
        $delegate = $this->createMock(AdapterInterface::class);
        $delegate->expects(self::once())->method('commit')->willReturn(true);

        self::assertTrue($this->pool($delegate, $this->telemetryFor($meter))->commit());

        self::assertNull(Context::storage()->scope());
        self::assertStringContainsString('Duration recording failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function tagInvalidationRecordsDurationWithoutTheTags(): void
    {
        $delegate = $this->createMock(TagAwareAdapterInterface::class);
        $delegate->expects(self::once())->method('invalidateTags')->with(['secret-tag'])->willReturn(false);
        $pool = new TraceableTagAwareCachePool($delegate, $this->cacheTelemetry, 'cache.tags');
        self::assertNotInstanceOf(NamespacedPoolInterface::class, $pool);
        self::assertFalse($pool->invalidateTags(['secret-tag']));
        self::assertSame(['cache.invalidateTags'], $this->exportedNames());
        $attributes = [
            'cache.pool.name' => 'cache.tags',
            'cache.operation.name' => 'invalidateTags',
        ];
        self::assertEquals(
            $attributes + ['cache.tags' => ['secret-tag']],
            $this->exportedSpan()->getAttributes()->toArray(),
        );

        // The tags stay on the span: they are unbounded, and a duration split by tag
        // would be a timeseries per invalidation.
        $metric = $this->metric('cache.operation.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame($attributes, $point->attributes->toArray());
            self::assertSame(1, $point->count);
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function namespacedTagInvalidationKeepsSymfonySemantics(): void
    {
        $pool = new TraceableNamespacedTagAwareCachePool(
            new TagAwareAdapter(new ArrayAdapter()),
            $this->cacheTelemetry,
            'cache.tags',
        );
        $child = $pool->withSubNamespace('tenant');
        self::assertNotSame($pool, $child);
        $rootItem = $pool->getItem('key')->set('root')->tag('products');
        $childItem = $child->getItem('key')->set('child')->tag('products');
        self::assertTrue($pool->save($rootItem));
        self::assertTrue($child->save($childItem));
        self::assertSame('root', $pool->getItem('key')->get());
        self::assertSame('child', $child->getItem('key')->get());
        self::assertTrue($child->invalidateTags(['products']));
        self::assertFalse($pool->getItem('key')->isHit());
        self::assertFalse($child->getItem('key')->isHit());
        self::assertSame('cache.tags', $this->exportedSpan(6)->getAttributes()->get('cache.pool.name'));
        self::assertSame('cache.invalidateTags', $this->exportedSpan(6)->getName());
    }
}
