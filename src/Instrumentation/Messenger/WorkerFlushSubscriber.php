<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Flushes telemetry for `messenger:consume`, which never reaches `kernel.terminate`.
 *
 * `WorkerRunningEvent` flushes at most once per configured interval; `WorkerStoppedEvent`
 * always flushes. Registered whenever Messenger is installed.
 */
final readonly class WorkerFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BoundaryFlush $flusher,
    ) {}

    /** @return array<class-string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => ['onRunning', -8192],
            WorkerStoppedEvent::class => ['onStopped', -8192],
        ];
    }

    public function onRunning(): void
    {
        $this->flusher->atBoundary();
    }

    public function onStopped(): void
    {
        $this->flusher->atShutdown();
    }
}
