<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\LazyCacheRead;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableNamespacedCachePool;
use Nmspaced\TelemetryWeaver\Internal\Operation\PendingOperations;
use Nmspaced\TelemetryWeaver\Tests\Support\CacheTelemetryTestCase;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A batch read that never reaches its end, in a worker that outlives the request. It must neither
 * claim a read that nobody saw finish nor stay open for the life of the process.
 */
#[CoversClass(LazyCacheRead::class)]
#[CoversClass(PendingOperations::class)]
final class UnfinishedCacheReadTest extends CacheTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aResultDroppedUnreadRecordsNothing(): void
    {
        $items = $this->pool(new ArrayAdapter())->getItems(['key']);
        unset($items);

        self::assertSame([], $this->exportedNames());
        self::assertSame(0, $this->recorded()['durations']);
        self::assertNull(Context::storage()->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function aWorkerResetAbandonsAnUnreadBatch(): void
    {
        $pool = $this->pool(new ArrayAdapter());
        $items = $pool->getItems(['key']);

        $pool->reset();

        self::assertSame(['cache.getItems'], $this->exportedNames());
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan()->getStatus()->getCode());
        self::assertSame(['key'], \array_keys(\iterator_to_array($items)));
        self::assertSame(['cache.getItems'], $this->exportedNames(), 'nothing is recorded twice');
        self::assertSame(0, $this->recorded()['durations']);
    }

    /** @throws \Throwable */
    #[Test]
    public function theParentsResetAbandonsASubNamespaceRead(): void
    {
        $pool = new TraceableNamespacedCachePool(new ArrayAdapter(), $this->cacheTelemetry, 'cache.test');
        $items = $pool->withSubNamespace('tenant')->getItems(['key']);

        $pool->reset();

        self::assertSame(['cache.getItems'], $this->exportedNames());
        self::assertSame(0, $this->recorded()['durations']);
        unset($items);
    }
}
