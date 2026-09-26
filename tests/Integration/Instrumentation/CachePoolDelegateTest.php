<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\CacheTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableCachePool;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/** The decorator behaves like whatever pool it wraps, including pools that are not Symfony's own. */
#[CoversClass(TraceableCachePool::class)]
#[CoversClass(CacheTelemetry::class)]
final class CachePoolDelegateTest extends TelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function anEagerlyReturnedBatchEndsItsOperationRightAway(): void
    {
        $item = new CacheItem();
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate->method('getItems')->willReturn(['a' => $item]);

        $items = $this->pool($delegate)->getItems(['a']);

        self::assertSame(['a' => $item], $items);
        self::assertSame(['cache.getItems'], $this->exportedNames(), 'nothing is left to read later');
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aBatchReadFailureEndsTheOperationAsAnErrorAndReachesTheCaller(): void
    {
        $failure = new \RuntimeException('backend is gone');
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate->method('getItems')->willThrowException($failure);

        try {
            $this->pool($delegate)->getItems(['a']);
            self::fail('The backend failure must reach the caller');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(StatusCode::STATUS_ERROR, $this->exportedSpan()->getStatus()->getCode());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aPlainPsr6PoolDeletesThroughDeleteItem(): void
    {
        $delegate = $this->createMock(AdapterInterface::class);
        $delegate->expects(self::once())->method('deleteItem')->with('orders')->willReturn(true);

        self::assertTrue($this->pool($delegate)->delete('orders'));
        self::assertSame(['cache.delete'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function resettingTheDecoratorResetsTheWrappedPool(): void
    {
        $delegate = new ArrayAdapter();
        $pool = $this->pool($delegate);
        $delegate->save($delegate->getItem('orders')->set('cached'));

        $pool->reset();

        self::assertFalse($delegate->hasItem('orders'));
    }

    /** @throws \Throwable */
    #[Test]
    public function aKeyThatIsNotAStringIsLeftToThePoolAndNotRecorded(): void
    {
        // Whether such a key is valid is the pool's decision, and Symfony's answer differs
        // between versions; the decorator only has to pass it on and keep it off the span.
        $item = new CacheItem();
        $delegate = $this->createMock(AdapterInterface::class);
        $delegate->expects(self::once())->method('getItem')->with(42)->willReturn($item);

        self::assertSame($item, $this->pool($delegate)->getItem(42));
        self::assertNull($this->exportedSpan()->getAttributes()->get('cache.key'));
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan()->getStatus()->getCode());
        $this->assertNoReports();
    }

    private function pool(AdapterInterface $delegate): TraceableCachePool
    {
        return new TraceableCachePool(
            $delegate,
            new CacheTelemetry(TelemetryFactory::create(
                new NoopMeter(),
                $this->spans,
                $this->reporter,
                new FrozenClock(),
            )),
            'cache.test',
        );
    }
}
