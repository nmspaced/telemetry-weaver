<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\TracingEventDispatcher;
use Nmspaced\TelemetryWeaver\Instrumentation\HandlerCall;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderListener;
use Nmspaced\TelemetryWeaver\Tests\Support\EventListenerTelemetryTestCase;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\EventDispatcher\Event;

/** What stopping, throwing and running outside a trace do to listener spans. */
#[CoversClass(TracingEventDispatcher::class)]
#[CoversClass(HandlerCall::class)]
final class EventListenerOutcomeTest extends EventListenerTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function stoppedPropagationSkipsTheRemainingListenersAndTheirSpans(): void
    {
        $this->activateParent();
        $listener = new OrderListener();
        $this->dispatcher->addListener('order.placed', [$listener, 'stop'], 10);
        $this->dispatcher->addListener('order.placed', [$listener, 'fail']);

        $this->dispatcher->dispatch(new Event(), 'order.placed');

        self::assertSame(['OrderListener::stop'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function aThrowingListenerFailsItsSpanAndTheExceptionReachesTheCaller(): void
    {
        $this->activateParent();
        $this->dispatcher->addListener('order.placed', [new OrderListener(), 'fail']);

        try {
            $this->dispatcher->dispatch(new Event(), 'order.placed');
            self::fail('the listener exception was swallowed');
        } catch (\RuntimeException $exception) {
            self::assertSame('listener failed', $exception->getMessage());
        }

        $span = $this->exportedSpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
    }

    /** @throws \Throwable */
    #[Test]
    public function aClientErrorLeavesTheListenerStatusUnset(): void
    {
        $this->activateParent();
        $this->dispatcher->addListener('order.placed', [new OrderListener(), 'missing']);

        $this->expectException(NotFoundHttpException::class);

        try {
            $this->dispatcher->dispatch(new Event(), 'order.placed');
        } finally {
            self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan()->getStatus()->getCode());
            self::assertSame([], $this->exportedSpan()->getEvents());
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function outsideATraceNoSpanIsOpened(): void
    {
        $called = false;
        $this->dispatcher->addListener('order.placed', [
            new OrderListener(static function () use (&$called): void {
                $called = true;
            }),
            'onPlaced',
        ]);

        $this->dispatcher->dispatch(new Event(), 'order.placed');

        self::assertTrue($called);
        self::assertSame([], $this->exported());
    }
}
