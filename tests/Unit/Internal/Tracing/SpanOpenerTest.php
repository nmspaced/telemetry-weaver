<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Tests\Fake\ThrowingTracer;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * What opening a span does to the context stack: who the parent is, and whose
 * stack an operation unwinds when it ends.
 */
#[CoversClass(SpanOpener::class)]
final class SpanOpenerTest extends TelemetryTestCase
{
    /**
     * A telemetry setup failure is not an application failure: the caller gets an
     * OwnedSpan it can use exactly like a real one, which happens to collect nothing.
     */
    #[Test]
    public function aFailedSpanCreationYieldsAnInertSpanAndIsReported(): void
    {
        $opener = new SpanOpener(new ThrowingTracer(), $this->contextStorage, $this->reporter);
        $baseline = $this->contextStorage->scope();

        $owner = $opener->open('operation', new SpanOptions());
        $owner->enrich(static fn(SpanInterface $span): SpanInterface => $span->setAttribute('ignored', true));
        $owner->finish();

        self::assertSame([], $this->exported(), 'an inert span has nothing to export');
        self::assertFalse($owner->spanContext()->isValid());
        self::assertSame($baseline, $this->contextStorage->scope(), 'a failure must not leave a scope behind');
        self::assertStringContainsString('Span creation failed', $this->logger->messageAt(0));
    }

    #[Test]
    public function nestedOperationsNestAndUnwindInOrder(): void
    {
        $baseline = $this->contextStorage->scope();

        $outer = $this->spans->open('outer', new SpanOptions());
        $inner = $this->spans->open('inner', new SpanOptions());
        $inner->finish();

        $outer->finish();

        $innerSpan = $this->exportedSpan(0);
        $outerSpan = $this->exportedSpan(1);
        self::assertSame('inner', $innerSpan->getName());
        self::assertSame($outerSpan->getContext()->getSpanId(), $innerSpan->getParentContext()->getSpanId());
        self::assertSame($baseline, $this->contextStorage->scope());
        $this->assertNoReports();
    }

    /**
     * An explicit parent is the whole point of the option: a consumer span must not
     * inherit the worker's ambient context, which belongs to the previous message.
     */
    #[Test]
    public function anExplicitRootParentIgnoresTheAmbientSpan(): void
    {
        $outer = $this->spans->open('outer', new SpanOptions());
        $detached = $this->spans->open('detached', SpanOptions::root());
        $detached->finish();

        $outer->finish();

        $detachedSpan = $this->exportedSpan(0);
        $outerSpan = $this->exportedSpan(1);
        self::assertFalse($detachedSpan->getParentContext()->isValid());
        self::assertNotSame($outerSpan->getContext()->getTraceId(), $detachedSpan->getContext()->getTraceId());
    }

    /**
     * Two fibers each own their operation; neither may unwind the other's context.
     *
     * @throws \Throwable
     */
    #[Test]
    public function independentFibersDoNotDisturbEachOther(): void
    {
        $this->useFiberBoundStorage();

        $first = $this->operationInAFiber('first');
        $second = $this->operationInAFiber('second');
        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertSame(['first', 'second'], $this->exportedNames());
        $this->assertNoReports();
    }

    /**
     * A fiber that opens one span, suspends inside it, and checks on resume that its
     * own stack came back — not somebody else's.
     *
     * @param non-empty-string $name
     *
     * @throws \Throwable
     */
    private function operationInAFiber(string $name): \Fiber
    {
        return new \Fiber(
            /** @throws \Throwable */
            function () use ($name): void {
                Context::getRoot()->activate();
                $before = $this->contextStorage->scope();

                $owner = $this->spans->open($name, new SpanOptions());
                \Fiber::suspend();
                $owner->finish();

                self::assertSame($before, $this->contextStorage->scope(), $name . ' must restore its own stack');
            },
        );
    }
}
