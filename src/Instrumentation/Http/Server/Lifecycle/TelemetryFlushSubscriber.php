<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle;

use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes telemetry on `kernel.terminate`, after the response has been sent.
 *
 * A worker that keeps its kernel gets a boundary flush; FPM and kernel-resetting workers get
 * the final shutdown. Runs after the server span has ended (priority below -2048).
 */
final readonly class TelemetryFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BoundaryFlush $flusher,
        private SymfonyRuntimeProfile $runtime,
    ) {}

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => [['onTerminate', -4096]],
        ];
    }

    public function onTerminate(): void
    {
        if ($this->runtime->finishesAfterRequest()) {
            $this->flusher->atShutdown();

            return;
        }

        $this->flusher->atBoundary();
    }
}
