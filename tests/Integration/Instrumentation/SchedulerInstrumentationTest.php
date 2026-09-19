<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Scheduler\SchedulerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Tests\Support\FrameworkInstrumentationTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\EventListener\DispatchSchedulerEventListener;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/**
 * `SchedulerTelemetrySubscriber` behaviour: success/failure/cancellation span boundaries,
 * reset abandoning an unfinished task without measuring it, and the handoff to the messenger
 * consumption span when scheduler events are themselves dispatched by a worker.
 */
final class SchedulerInstrumentationTest extends FrameworkInstrumentationTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function schedulerSuccessFailureAndCancellationRespectTheirBoundaries(): void
    {
        $subscriber = new SchedulerTelemetrySubscriber($this->telemetry, $this->reporter);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $schedule = new Schedule();
        $message = new \stdClass();
        $context = new MessageContext('default', 'task-id', new PeriodicalTrigger('1 hour'), new \DateTimeImmutable());
        $parent = $this->telemetry->operation('messenger.consume')->start();
        $dispatcher->dispatch(new PreRunEvent($schedule, $context, $message));
        $dispatcher->dispatch(new PostRunEvent($schedule, $context, $message));
        self::assertSame($parent->span()->spanId(), $this->span(0)->getParentContext()->getSpanId());
        $dispatcher->dispatch(new PreRunEvent($schedule, $context, $message));
        $dispatcher->dispatch(new FailureEvent($schedule, $context, $message, new \RuntimeException('failed')));
        self::assertSame(StatusCode::STATUS_ERROR, $this->span(1)->getStatus()->getCode());
        $cancelled = new PreRunEvent($schedule, $context, $message);
        $cancelled->shouldCancel(true);

        $dispatcher->dispatch($cancelled);
        self::assertCount(2, $this->telemetry->spans());
        $parent->finish();
        $points = MetricPoints::of($this->measurement());
        self::assertCount(2, $points);
        foreach ($points as $point) {
            self::assertNull($point->attributes->get('scheduler.task.id'));
        }
    }

    #[Test]
    public function schedulerResetReleasesAnUnfinishedTaskWithoutMeasuringIt(): void
    {
        $subscriber = new SchedulerTelemetrySubscriber($this->telemetry, $this->reporter);
        $context = new MessageContext('default', 'id', new PeriodicalTrigger('1 hour'), new \DateTimeImmutable());
        $subscriber->onPreRun(new PreRunEvent(new Schedule(), $context, new \stdClass()));
        $subscriber->reset();
        self::assertNull($this->telemetry->activeTrace());
        self::assertCount(1, $this->telemetry->spans());
        foreach ($this->telemetry->measurements() as $metric) {
            self::assertCount(0, MetricPoints::of($metric));
        }
    }

    #[Test]
    public function schedulerEventsDispatchedByMessengerCloseBeforeTheConsumptionBoundary(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SchedulerTelemetrySubscriber($this->telemetry, $this->reporter));

        $schedule = new Schedule();
        $locator = new ServiceLocator(['default' => static fn(): Schedule => $schedule]);
        $dispatcher->addSubscriber(new DispatchSchedulerEventListener($locator, $dispatcher));
        $context = new MessageContext('default', 'id', new PeriodicalTrigger('1 hour'), new \DateTimeImmutable());
        $envelope = new Envelope(new \stdClass(), [new ScheduledStamp($context)]);
        $parent = $this->telemetry->operation('messenger.consume')->start();
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        self::assertNotSame($parent->span()->spanId(), $this->telemetry->activeTrace()['span_id'] ?? null);
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));
        self::assertSame($parent->span()->spanId(), $this->telemetry->activeTrace()['span_id'] ?? null);
        $parent->finish();
        self::assertCount(2, $this->telemetry->spans());
    }
}
