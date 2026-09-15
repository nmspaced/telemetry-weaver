<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Console;

use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** @internal Runs after command spans finish, even when console tracing itself is disabled. */
final class ConsoleFlushSubscriber implements EventSubscriberInterface
{
    private int $depth = 0;

    public function __construct(
        private readonly TelemetryFlusher $flusher,
    ) {}

    /** @return array<string, array{string, int}> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 8192],
            ConsoleEvents::TERMINATE => ['onTerminate', -8192],
        ];
    }

    public function onCommand(): void
    {
        ++$this->depth;
    }

    public function onTerminate(): void
    {
        $this->depth = \max(0, $this->depth - 1);
        if ($this->depth === 0) {
            $this->flusher->atShutdown();
        }
    }
}
