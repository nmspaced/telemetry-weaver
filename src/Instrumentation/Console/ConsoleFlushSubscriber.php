<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Console;

use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal Flushes telemetry after a top-level command, even with console tracing off.
 *
 * Under a web runtime it does nothing, since a command run inside a request is not a
 * boundary. Elsewhere it flushes rather than seals; sealing happens at process shutdown.
 */
final class ConsoleFlushSubscriber implements EventSubscriberInterface
{
    private int $depth = 0;

    public function __construct(
        private readonly BoundaryFlush $flusher,
        private readonly SymfonyRuntimeProfile $runtime,
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

        if ($this->depth !== 0 || !$this->runtime->commandsAreBoundaries()) {
            return;
        }

        $this->flusher->atBoundary();
    }
}
