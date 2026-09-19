<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * The execution boundary of a consumer, which otherwise has none.
 *
 * `kernel.terminate` — where HTTP delivers its telemetry — is never dispatched by
 * `messenger:consume`. Without this subscriber a worker's metrics reach the collector
 * only when the process exits, and with the batch span processor its spans do too: the
 * SDK has no timer, so a queued batch waits for `maxExportBatchSize` or for a flush
 * somebody else starts. A worker killed before shutdown loses both.
 *
 * `WorkerRunningEvent` fires after every message *and* on every idle poll, which is far
 * too often to export on: `FlushPolicy` holds it down to the configured interval, and
 * each flush is a blocking export. `WorkerStoppedEvent` flushes unconditionally —
 * there is no next boundary to defer to.
 *
 * Registered whenever Messenger is available, not only when messenger instrumentation
 * is on: an application whose HTTP and database telemetry is enabled still needs its
 * consumers to deliver what they recorded.
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
