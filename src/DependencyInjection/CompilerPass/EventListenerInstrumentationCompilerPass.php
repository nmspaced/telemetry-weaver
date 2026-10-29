<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\DefinitionClass;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\ListenerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\TracingEventDispatcher;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Builds every plain Symfony event dispatcher as {@see TracingEventDispatcher}: `event_dispatcher`,
 * and the per-firewall dispatchers security listeners are registered on.
 *
 * Only a definition of exactly `EventDispatcher`, with no arguments and no factory, is changed.
 * Anything else was built that way on purpose, and swapping its class would change behaviour
 * this bundle cannot see.
 */
final readonly class EventListenerInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)->instruments('event_listeners');

        if ($gate->isClosed()) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            if (!$this->isPlainDispatcher($container, $definition)) {
                continue;
            }

            $definition
                ->setClass(TracingEventDispatcher::class)
                ->setArgument('$listenerTelemetry', new Reference(ListenerTelemetry::class));
        }
    }

    private function isPlainDispatcher(ContainerBuilder $container, Definition $definition): bool
    {
        return (
            !$definition->isAbstract()
            && !$definition->isSynthetic()
            && $definition->getFactory() === null
            && $definition->getArguments() === []
            && DefinitionClass::of($container, $definition) === EventDispatcher::class
        );
    }
}
