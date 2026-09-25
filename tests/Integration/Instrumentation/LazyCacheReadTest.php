<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\LazyCacheRead;
use Nmspaced\TelemetryWeaver\Tests\Support\CacheTelemetryTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/**
 * `getItems()` over a backend that reads while the caller iterates: the read ends with the
 * iteration, records what the iteration did, and keeps the caller's loop body out of both the trace
 * and the duration.
 */
#[CoversClass(LazyCacheRead::class)]
final class LazyCacheReadTest extends CacheTelemetryTestCase
{
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
    public function aBackendFailureDuringIterationFailsTheRead(): void
    {
        $item = new ArrayAdapter()->getItem('first');
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate
            ->method('getItems')
            ->willReturnCallback(
                /**
                 * @return \Generator<string, CacheItem>
                 * @throws \RuntimeException
                 */
                static function () use ($item): \Generator {
                    yield 'first' => $item;

                    throw new \RuntimeException('connection lost');
                },
            );

        $items = $this->pool($delegate)->getItems(['first', 'second']);
        self::assertSame([], $this->exportedNames(), 'nothing has been read yet');

        try {
            \iterator_to_array($items);
            self::fail('The backend exception must reach the caller.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('connection lost', $runtimeException->getMessage());
        }

        $span = $this->exportedSpan();
        self::assertSame('cache.getItems', $span->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
        self::assertSame(
            \RuntimeException::class,
            MetricPoints::firstHistogram($this->metric('cache.operation.duration'))->attributes->get('error.type'),
        );
        self::assertNull(Context::storage()->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function theDurationExcludesTheCallersLoopBody(): void
    {
        $clock = $this->clock;
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate
            ->method('getItems')
            ->willReturnCallback(
                /**
                 * @return \Generator<string, CacheItem>
                 * @throws InvalidArgumentException
                 */
                static function () use ($clock): \Generator {
                    $cache = new ArrayAdapter();
                    $clock->advanceSeconds(0.25);
                    yield 'a' => $cache->getItem('a');
                    $clock->advanceSeconds(0.25);
                    yield 'b' => $cache->getItem('b');
                },
            );

        foreach ($this->pool($delegate)->getItems(['a', 'b']) as $_item) {
            $clock->advanceSeconds(10);
        }

        self::assertEqualsWithDelta(
            0.5,
            MetricPoints::firstHistogram($this->metric('cache.operation.duration'))->sum,
            1e-9,
        );
        self::assertSame(['cache.getItems'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function breakingOutOfTheLoopAbandonsTheRead(): void
    {
        // @mago-expect lint:loop-does-not-iterate — deliberately abandons a partially consumed batch
        foreach ($this->pool(new ArrayAdapter())->getItems(['a', 'b']) as $_item) {
            break;
        }

        self::assertSame(['cache.getItems'], $this->exportedNames());
        self::assertSame(['durations' => 0, 'lookups' => 1], $this->recorded(), 'the item read is still counted');
    }

    /** @throws \Throwable */
    #[Test]
    public function anArrayResultEndsWithTheCall(): void
    {
        $item = new ArrayAdapter()->getItem('key');
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate->method('getItems')->willReturn(['key' => $item]);

        $items = $this->pool($delegate)->getItems(['key']);

        self::assertSame(['key' => $item], $items);
        self::assertSame(['cache.getItems'], $this->exportedNames());
        self::assertSame(['durations' => 1, 'lookups' => 1], $this->recorded());
    }

    /** @throws \Throwable */
    #[Test]
    public function backendWorkIsAChildOfTheReadAndTheLoopBodyIsNot(): void
    {
        $tracing = TelemetryFactory::tracing($this->spans, $this->reporter);
        $delegate = $this->createStub(AdapterInterface::class);
        $delegate
            ->method('getItems')
            ->willReturnCallback(
                /**
                 * @return \Generator<string, CacheItem>
                 * @throws \Throwable
                 */
                static function () use ($tracing): \Generator {
                    $cache = new ArrayAdapter();

                    foreach (['a', 'b'] as $key) {
                        $tracing->trace('backend.read', static fn(): null => null);

                        yield $key => $cache->getItem($key);
                    }
                },
            );

        foreach ($this->pool($delegate)->getItems(['a', 'b']) as $_item) {
            $tracing->trace('loop.body', static fn(): null => null);
        }

        $ids = [];
        $parents = [];

        foreach ($this->exported() as $span) {
            $ids[$span->getName()] = $span->getSpanId();
            $parents[$span->getName()][] = $span->getParentContext()->getSpanId();
        }

        $read = $ids['cache.getItems'] ?? self::fail('the read was not exported');
        self::assertSame([$read, $read], $parents['backend.read'] ?? []);
        self::assertNotContains($read, $parents['loop.body'] ?? []);
        self::assertSame(
            ['backend.read', 'loop.body', 'backend.read', 'loop.body', 'cache.getItems'],
            $this->exportedNames(),
        );
        self::assertNull(Context::storage()->scope());
    }
}
