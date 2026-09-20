<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Console;

use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal Runs after command spans finish, even when console tracing itself is disabled.
 *
 * Two things this deliberately does *not* do, both of them because a finished command is
 * not on its own evidence that anything is over:
 *
 *  - It finalizes nothing under a web runtime. A controller running a console Application
 *    with the kernel's dispatcher — `setAutoExit(false)`, the ordinary programmatic call —
 *    reaches TERMINATE while the request, and in a worker the whole process, is still
 *    running. Sealing there closed the export gate and shut the providers down for every
 *    later request of that worker. The HTTP boundary owns finalization where there is one;
 *    a command nested in a request is not a boundary of anything. Flushing instead of
 *    sealing would be no better: it would move a blocking export onto the request's path.
 *  - Outside a web runtime it flushes the boundary rather than sealing the pipeline. The
 *    depth counter only knows about *nested* commands, so a second top-level
 *    `Application::run()` in the same process — a custom CLI, a test harness — used to
 *    find a pipeline the first run had already shut down. Sealing belongs to the end of
 *    the PHP execution, and `ExportGate` already runs one final budgeted `atShutdown()`
 *    there for every pipeline that outlives its units of work, this one included.
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
