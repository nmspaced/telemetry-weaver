<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle;

use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The HTTP execution boundary for telemetry delivery.
 *
 * TERMINATE, because the front controller calls Response::send() before
 * Kernel::terminate(): the export blocks, and here the client already has
 * its response, so the block is invisible to it. Only the main request
 * reaches TERMINATE — HttpKernel dispatches it once, for the main request —
 * so a sub-request can never close the pipeline.
 *
 * What the boundary is depends on the runtime profile. A worker that keeps
 * its kernel serves the next request with the same providers, so this is a
 * scheduled boundary. FPM and a kernel-resetting worker never see these
 * providers again, so this is the final shutdown: one last collection, then
 * the pipeline is sealed and anything later is dropped instead of exported
 * from a PHP shutdown callback with no budget.
 *
 * The priority is below HttpServerTracingSubscriber's -2048 on the same event.
 * That is not cosmetic — the base design fixes the order as end() of the main
 * request's span, then assertRestored(), then the flush: telemetry for this
 * execution has to be consistent before anything is exported.
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

    /**
     * Takes no event: the flush needs nothing from it, and getSubscribedEvents() already records what this is bound to.
     */
    public function onTerminate(): void
    {
        if ($this->runtime->finishesAfterRequest()) {
            $this->flusher->atShutdown();

            return;
        }

        $this->flusher->atBoundary();
    }
}
