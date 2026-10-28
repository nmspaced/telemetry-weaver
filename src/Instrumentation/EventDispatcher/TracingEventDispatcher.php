<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher;

use Psr\EventDispatcher\StoppableEventInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The Symfony dispatcher, with each listener call handed to {@see ListenerTelemetry}.
 *
 * A subclass rather than a decorator: `callListeners()` is the documented extension point, and
 * the listeners, their order and `removeListener()` stay exactly Symfony's. A subclass also
 * opts out of the dispatcher's closure optimisation, so every listener of an event is created
 * on its first dispatch instead of when first called — what the profiler's dispatcher does in
 * debug, and once per process in a worker.
 *
 * @internal
 */
final class TracingEventDispatcher extends EventDispatcher
{
    public function __construct(
        private readonly ListenerTelemetry $listenerTelemetry,
    ) {
        parent::__construct();
    }

    /**
     * The parent's loop, with each call inside a listener span.
     *
     * @param iterable<callable> $listeners
     *
     * @throws \Throwable
     */
    #[\Override]
    protected function callListeners(iterable $listeners, string $eventName, object $event): void
    {
        $stoppable = $event instanceof StoppableEventInterface;

        foreach ($listeners as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }

            $this->listenerTelemetry->call(
                $listener,
                $eventName,
                /** @throws \Throwable */
                function () use ($listener, $event, $eventName): void {
                    $listener($event, $eventName, $this);
                },
            );
        }
    }
}
