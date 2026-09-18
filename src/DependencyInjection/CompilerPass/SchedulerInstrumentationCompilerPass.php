<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Scheduler\SchedulerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Scheduler\Event\PreRunEvent;

/**
 * Scheduled task runs, as seen from the Messenger worker that executes them.
 *
 * Nothing to decorate: Symfony Scheduler dispatches its own events around each run, and
 * the subscriber keys per-run state on the `MessageContext` those events carry, which is
 * the same instance for the pre-run and the post-run of one execution.
 */
final readonly class SchedulerInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/scheduler', PreRunEvent::class)
            ->instruments('scheduler');

        if ($gate->isClosed()) {
            return;
        }

        $container
            ->register(SchedulerTelemetrySubscriber::class, SchedulerTelemetrySubscriber::class)
            ->setArgument('$telemetry', new Reference('open_telemetry.scheduler.telemetry'))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->setArgument('$buckets', new Reference('open_telemetry.scheduler.buckets'))
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset']);
    }
}
