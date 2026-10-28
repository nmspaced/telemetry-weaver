<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\DecoratedService;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\TraceableArgumentResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Spans controller argument resolution and the controller call.
 *
 * The call is wrapped by a kernel subscriber; argument resolution by a decorator of
 * `argument_resolver`, which is skipped when the application has none.
 */
final readonly class ControllerInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string ARGUMENT_RESOLVER_ID = 'argument_resolver';

    /** Innermost, inside `debug.argument_resolver`, so the profiler's timing includes this one. */
    private const int DECORATION_PRIORITY = 32;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)->needs('http_kernel')->instruments('controller');

        if ($gate->isClosed()) {
            return;
        }

        $container
            ->register(ControllerTelemetrySubscriber::class, ControllerTelemetrySubscriber::class)
            ->setArgument('$controllerTelemetry', new Reference(ControllerTelemetry::class))
            ->addTag('kernel.event_subscriber');

        if (!$container->has(self::ARGUMENT_RESOLVER_ID)) {
            return;
        }

        $innerId = DecoratedService::innerId(self::ARGUMENT_RESOLVER_ID);

        $container
            ->register(DecoratedService::id(self::ARGUMENT_RESOLVER_ID), TraceableArgumentResolver::class)
            ->setDecoratedService(self::ARGUMENT_RESOLVER_ID, $innerId, self::DECORATION_PRIORITY)
            ->setArgument('$delegate', new Reference($innerId))
            ->setArgument('$controllerTelemetry', new Reference(ControllerTelemetry::class));
    }
}
