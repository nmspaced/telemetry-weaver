<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/** Counts deliveries, including messages vetoed by another listener. Never activates a scope. */
final readonly class MessengerWorkerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessengerTelemetry $messengerTelemetry,
        private InstrumentationFailureReporter $reporter,
        private MessagingSystem $systems = new MessagingSystem(),
    ) {}

    /** @return array<class-string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageReceivedEvent::class => ['onReceived', 4096]];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        try {
            $destination = $event->getReceiverName();
            $destination = $destination === '' ? 'unknown' : $destination;
            $this->messengerTelemetry->received(MessageAttributes::of(
                'process',
                MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_PROCESS,
                $destination,
                $event->getEnvelope()->getMessage(),
                $this->systems->ofReceiver($destination),
            ));
        } catch (\Throwable $throwable) {
            $this->reporter->report('Messenger delivery measurement failed', 'process', $throwable);
        }
    }
}
