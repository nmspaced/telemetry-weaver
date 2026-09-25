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

/** Registers the subscriber that traces scheduled task runs inside the Messenger worker. */
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
