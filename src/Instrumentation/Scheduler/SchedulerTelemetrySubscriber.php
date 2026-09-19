<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Scheduler;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Execution\ExecutionRegistry;
use Nmspaced\TelemetryWeaver\Internal\Execution\OperationExecution;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Contracts\Service\ResetInterface;

/** @internal Scheduler execution nests inside Messenger consumption; schedules and message types are bounded metric labels. */
final readonly class SchedulerTelemetrySubscriber implements EventSubscriberInterface, ResetInterface
{
    /** @var ExecutionRegistry<OperationExecution> */
    private ExecutionRegistry $executions;

    private Duration $duration;

    public function __construct(
        private Telemetry $telemetry,
        InstrumentationFailureReporter $reporter,
        OperationBuckets $buckets = DefaultBuckets::ScheduledTask,
    ) {
        $this->executions = new ExecutionRegistry($reporter, 'scheduler');
        $this->duration = $telemetry->metrics()->duration(
            'scheduler.task.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of executing a scheduled task.',
        );
    }

    /** @return array<class-string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            PreRunEvent::class => ['onPreRun', -4096],
            PostRunEvent::class => ['onPostRun', -4096],
            FailureEvent::class => ['onFailure', -4096],
        ];
    }

    public function onPreRun(PreRunEvent $event): void
    {
        if ($event->shouldCancel()) {
            return;
        }

        $context = $event->getMessageContext();
        $attributes = [
            'scheduler.schedule.name' => $context->name,
            'scheduler.message.type' => $event->getMessage()::class,
        ];
        $this->executions->open($context, fn(): OperationExecution => OperationExecution::started(
            $this->telemetry
                ->operation('scheduler.run')
                ->attributes($attributes + ['scheduler.task.id' => $context->id])
                ->duration($this->duration, attributes: $attributes)
                ->start(),
        ));
    }

    public function onPostRun(PostRunEvent $event): void
    {
        $this->executions->close($event->getMessageContext());
    }

    public function onFailure(FailureEvent $event): void
    {
        $context = $event->getMessageContext();
        $this->executions->of($context)?->fail($event->getError());
        $this->executions->close($context);
    }

    #[\Override]
    public function reset(): void
    {
        $this->executions->reset();
    }
}
