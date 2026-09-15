<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Console commands, and the export boundary a console process would otherwise never reach.
 *
 * The two halves are registered independently on purpose. A command run exports nothing
 * without the flush subscriber — `kernel.terminate` never fires outside an HTTP request,
 * and the SDK has no timer of its own — so whatever a command produced through Doctrine,
 * the HTTP client or the application's own scopes would sit in a batch processor until
 * the process exits and take the batch with it. That makes the flush subscriber a
 * property of the bundle being on, not of console tracing being on, and it is why
 * switching `instrumentation.console` off still leaves a console process exporting.
 */
final readonly class ConsoleInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)->requires('symfony/console', ConsoleEvents::class);

        if ($gate->isClosed()) {
            return;
        }

        $container
            ->register(ConsoleFlushSubscriber::class, ConsoleFlushSubscriber::class)
            ->setArgument('$flusher', new Reference(TelemetryFlusher::class))
            ->addTag('kernel.event_subscriber');

        if ($gate->instruments('console')->isClosed()) {
            return;
        }

        $container
            ->register(ConsoleTelemetrySubscriber::class, ConsoleTelemetrySubscriber::class)
            ->setArgument('$telemetry', new Reference('open_telemetry.console.telemetry'))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->setArgument(
                '$excludedCommands',
                $container->getParameter('open_telemetry.instrumentation.console.excluded_commands'),
            )
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset']);
    }
}
