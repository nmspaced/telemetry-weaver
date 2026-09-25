<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Console command tracing, and the flush subscriber a console process needs.
 *
 * The flush subscriber is registered even with console tracing off: without it nothing a
 * command records is exported until the process ends.
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
            ->setArgument('$flusher', new Reference(BoundaryFlush::class))
            ->setArgument('$runtime', new Reference(SymfonyRuntimeProfile::class))
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
            ->setArgument('$buckets', new Reference('open_telemetry.console.buckets'))
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset']);
    }
}
