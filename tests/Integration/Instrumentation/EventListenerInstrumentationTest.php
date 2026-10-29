<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\ListenerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\TracingEventDispatcher;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderListener;
use Nmspaced\TelemetryWeaver\Tests\Support\EventListenerTelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\Debug\TraceableEventDispatcher;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\EventDispatcher\Event;

#[CoversClass(TracingEventDispatcher::class)]
#[CoversClass(ListenerTelemetry::class)]
/**
 * Which listeners get a span, what it is called and where it sits. What a listener's outcome does
 * to its span lives in {@see EventListenerOutcomeTest}.
 */
final class EventListenerInstrumentationTest extends EventListenerTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aListenerInATracedNamespaceIsAChildSpanNamedAfterItsMethod(): void
    {
        $this->activateParent();
        $this->dispatcher->addListener('order.placed', [new OrderListener(), 'onPlaced']);

        $this->dispatcher->dispatch(new Event(), 'order.placed');

        $span = $this->exportedSpan();
        self::assertSame('OrderListener::onPlaced', $span->getName());
        self::assertSame(
            ['code.function.name' => OrderListener::class . '::onPlaced', 'listener.event.name' => 'order.placed'],
            $span->getAttributes()->toArray(),
        );
        self::assertSame($this->parent?->getContext()->getSpanId(), $span->getParentContext()->getSpanId());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aListenerOutsideTheNamespacesRunsWithoutASpan(): void
    {
        $this->activateParent();
        $called = false;
        // Declared here, so named after this test class, which is not under the traced prefix.
        $this->dispatcher->addListener('order.placed', static function () use (&$called): void {
            $called = true;
        });

        $this->dispatcher->dispatch(new Event(), 'order.placed');

        self::assertTrue($called);
        self::assertSame([], $this->exported());
    }

    /** @throws \Throwable */
    #[Test]
    public function theBundlesOwnListenersAreNeverTracedWhateverTheConfiguration(): void
    {
        $this->activateParent();
        $dispatcher = $this->dispatcherTracing(['Nmspaced\\']);
        $dispatcher->addListener('order.placed', [ControllerTelemetrySubscriber::class, 'getSubscribedEvents']);
        $dispatcher->addListener('order.placed', [new OrderListener(), 'onPlaced']);

        $dispatcher->dispatch(new Event(), 'order.placed');

        self::assertSame(['OrderListener::onPlaced'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function workInsideTheListenerIsAChildOfItsSpan(): void
    {
        $this->activateParent();
        $inside = null;
        $this->dispatcher->addListener('order.placed', [
            new OrderListener(function () use (&$inside): void {
                $inside = $this->activeTrace();
            }),
            'onPlaced',
        ]);

        $this->dispatcher->dispatch(new Event(), 'order.placed');

        self::assertInstanceOf(TraceContext::class, $inside);
        self::assertSame($this->exportedSpan()->getContext()->getSpanId(), $inside->spanId);
    }

    /** @throws \Throwable */
    #[Test]
    public function aNestedDispatchNestsItsListenerSpans(): void
    {
        $this->activateParent();
        $this->dispatcher->addListener('order.paid', [new OrderListener(), 'onPlaced']);
        $this->dispatcher->addListener('order.placed', [
            new OrderListener(function (): void {
                $this->dispatcher->dispatch(new Event(), 'order.paid');
            }),
            'onPlaced',
        ]);

        $this->dispatcher->dispatch(new Event(), 'order.placed');

        $inner = $this->exportedSpan(0);
        $outer = $this->exportedSpan(1);
        self::assertSame('order.paid', $inner->getAttributes()->get('listener.event.name'));
        self::assertSame($outer->getContext()->getSpanId(), $inner->getParentContext()->getSpanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function underTheProfilersDispatcherTheSpanIsNamedAfterTheListenerItWraps(): void
    {
        $this->activateParent();
        $profiled = new TraceableEventDispatcher($this->dispatcher, new Stopwatch());
        $profiled->addListener('order.placed', [new OrderListener(), 'onPlaced']);

        $profiled->dispatch(new Event(), 'order.placed');

        self::assertSame(['OrderListener::onPlaced'], $this->exportedNames());
        self::assertCount(1, $profiled->getCalledListeners());
    }
}
