<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerWorkerSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableMessageBusMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceContextStamp;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Tests\Fake\DeferredMessageHandler;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerMetricAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context as OtelContext;
use OpenTelemetry\Context\FiberBoundContextStorageExecutionAwareBC;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

final class MessengerLifecycleTest extends MessengerTelemetryTestCase
{
    /** @return iterable<string, array{class-string, bool, int}> */
    public static function listeners(): iterable
    {
        yield 'received before telemetry' => [WorkerMessageReceivedEvent::class, false, 8192];
        yield 'received after telemetry' => [WorkerMessageReceivedEvent::class, false, -8192];
        yield 'handled before old close' => [WorkerMessageHandledEvent::class, false, 0];
        yield 'handled after old close' => [WorkerMessageHandledEvent::class, false, -8192];
        yield 'failed before old close' => [WorkerMessageFailedEvent::class, true, 0];
        yield 'failed after old close' => [WorkerMessageFailedEvent::class, true, -8192];
    }

    /**
     * @param class-string $eventClass
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('listeners')]
    public function aThrowingWorkerListenerCannotStrandTheConsumer(string $eventClass, bool $fail, int $priority): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher, $fail);
        $error = new \RuntimeException('listener failed');
        $dispatcher->addListener(
            $eventClass,
            /** @throws \RuntimeException */ static function () use ($error): never {
                self::assertNull(
                    OtelContext::storage()->scope(),
                    'worker listeners must run outside the consumer scope',
                );
                throw $error;
            },
            $priority,
        );
        if ($eventClass === WorkerMessageHandledEvent::class) {
            $dispatcher->addListener(
                WorkerMessageFailedEvent::class,
                /** @throws \Throwable */ static function (WorkerMessageFailedEvent $event) use ($error): never {
                    self::assertSame($error, $event->getThrowable());
                    throw $event->getThrowable();
                },
            );
        }

        $bus->dispatch(new SampleMessage('first'));

        try {
            $this->work($telemetry, $dispatcher, $bus);
            self::fail('the original listener error must reach the caller');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertNull(OtelContext::storage()->scope());
        self::assertCount(
            $eventClass === WorkerMessageReceivedEvent::class ? 0 : 1,
            MessengerSpanAssertions::spansNamed($this->spans, 'process async'),
        );
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function aVetoedDeliveryDoesNotOpenAScopeOrRequireServiceReset(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (WorkerMessageReceivedEvent $event): void {
            $event->shouldHandle(false);
            self::assertNull(OtelContext::storage()->scope());
        });
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            $event->getWorker()->stop();
        });
        $bus->dispatch(new SampleMessage('skipped'));
        $this->work($telemetry, $dispatcher, $bus);
        $this->reader->collect();

        self::assertNull(OtelContext::storage()->scope());
        self::assertSame([], MessengerSpanAssertions::spansNamed($this->spans, 'process async'));
        self::assertSame(
            1,
            MessengerMetricAssertions::counter($this->metrics, 'messaging.client.consumed.messages')->value,
        );
        self::assertSame([], MessengerMetricAssertions::dataPointsOf($this->metrics, 'messaging.process.duration'));
    }

    /** @throws \Throwable */
    #[Test]
    public function consecutiveMessagesRestoreTheAmbientContextWithoutReset(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher, fail: true);
        $bus->dispatch(new SampleMessage('first'));
        $bus->dispatch(new SampleMessage('second'));

        $ambient = OtelContext::getRoot()->with(OtelContext::createKey('worker'), 'ambient');
        $scope = $ambient->activate();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function () use ($ambient): void {
            self::assertSame($ambient, OtelContext::getCurrent());
        });
        try {
            $this->work($telemetry, $dispatcher, $bus);
            self::assertSame($ambient, OtelContext::getCurrent());
        } finally {
            $scope->detach();
        }

        self::assertCount(2, MessengerSpanAssertions::spansNamed($this->spans, 'process async'));
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function propagationFailureStillCallsTheHandlerExactlyOnce(): void
    {
        $propagation = $this->createStub(Propagation::class);
        $propagation->method('extract')->willThrowException(new \RuntimeException('propagation failed'));
        $telemetry = $this->telemetry();
        $consumption = new MessengerConsumption(
            $telemetry,
            $propagation,
            new InstrumentationFailureReporter($this->logger),
        );
        $envelope = new Envelope(new SampleMessage('first'), [new TraceContextStamp(['traceparent' => 'broken'])]);
        $calls = 0;
        $error = new \RuntimeException('business error');
        try {
            $consumption->run(
                $envelope,
                'async',
                /** @throws \RuntimeException */ static function () use (&$calls, $error): never {
                    ++$calls;
                    throw $error;
                },
            );
            self::fail('the business error must reach the caller');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }

        self::assertSame(1, $calls);
        self::assertNull(OtelContext::storage()->scope());
        self::assertCount(1, $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function aNestedBusRestoresItsCallerBeforeTheOuterOperationEnds(): void
    {
        $telemetry = $this->telemetry();
        $consumption = $this->consumption($telemetry);
        $inner = new MessageBus([
            new TraceableMessageBusMiddleware($telemetry, 'inner', $consumption),
            new HandleMessageMiddleware(new HandlersLocator([
                SampleMessage::class => [static function (): void {
                    self::assertTrue(Span::getCurrent()->getContext()->isValid());
                }],
            ])),
        ]);
        $outer = new MessageBus([
            new TraceableMessageBusMiddleware($telemetry, 'outer', $consumption),
            new HandleMessageMiddleware(new HandlersLocator([
                SampleMessage::class => [/** @throws \Throwable */ static function () use ($inner): void {
                    $context = OtelContext::getCurrent();
                    $inner->dispatch(new SampleMessage('nested'));
                    self::assertSame($context, OtelContext::getCurrent());
                }],
            ])),
        ]);
        $outer->dispatch(new Envelope(new SampleMessage('outer'), [
            new ConsumedByWorkerStamp(),
            new ReceivedStamp('async'),
        ]));

        self::assertNull(OtelContext::storage()->scope());
        self::assertCount(1, MessengerSpanAssertions::spansNamed($this->spans, 'process async'));
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function deferredBatchAcknowledgementsDoNotKeepScopesBetweenDeliveries(): void
    {
        $telemetry = $this->telemetry();
        $handler = new DeferredMessageHandler();
        $bus = new MessageBus([
            new TraceableMessageBusMiddleware($telemetry, 'batch', $this->consumption($telemetry)),
            new HandleMessageMiddleware(new HandlersLocator([SampleMessage::class => [$handler]])),
        ]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(
            new MessengerWorkerSubscriber($telemetry, new InstrumentationFailureReporter($this->logger)),
        );
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            self::assertNull(
                OtelContext::storage()->scope(),
                'no active consumer while the batch waits for more messages',
            );
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        $receiver = $this->createMock(ReceiverInterface::class);
        $receiver
            ->expects(self::exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                [
                    new Envelope(new SampleMessage('first')),
                    new Envelope(new SampleMessage('second')),
                ],
                [],
                [],
            );
        $receiver->expects(self::exactly(2))->method('ack');
        $receiver->expects(self::never())->method('reject');
        $worker = new Worker(['async' => $receiver], $bus, $dispatcher);
        $worker->run(['sleep' => 0]);

        $this->reader->collect();

        self::assertSame(['first', 'second'], $handler->processed);
        self::assertNull(OtelContext::storage()->scope());
        self::assertSame([], $this->logger->messages());
        self::assertSame(
            2,
            MessengerMetricAssertions::counter($this->metrics, 'messaging.client.consumed.messages')->value,
        );
        self::assertGreaterThanOrEqual(
            3,
            \count(MessengerSpanAssertions::spansNamed($this->spans, 'process async')),
            'two deliveries and at least one batch flush',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function overlappingFibersOwnIndependentConsumerScopes(): void
    {
        OtelContext::setStorage(new FiberBoundContextStorageExecutionAwareBC());
        $consumption = $this->consumption($this->telemetry());
        $work = /** @throws \Throwable */ static function () use ($consumption): void {
            $root = OtelContext::getRoot()->activate();
            try {
                $envelope = new Envelope(new SampleMessage('fiber'));
                $consumption->run(
                    $envelope,
                    'async',
                    /** @throws \Throwable */ static function () use ($envelope): Envelope {
                        $context = OtelContext::getCurrent();
                        \Fiber::suspend();
                        self::assertSame($context, OtelContext::getCurrent());

                        return $envelope;
                    },
                );
                self::assertSame(OtelContext::getRoot(), OtelContext::getCurrent());
            } finally {
                $root->detach();
            }
        };
        $first = new \Fiber($work);
        $second = new \Fiber($work);
        $first->start();
        $second->start();
        self::assertNull(OtelContext::storage()->scope());
        $first->resume();
        self::assertCount(1, MessengerSpanAssertions::spansNamed($this->spans, 'process async'));
        $second->resume();

        self::assertCount(2, MessengerSpanAssertions::spansNamed($this->spans, 'process async'));
        self::assertSame([], $this->logger->messages());
    }
}
