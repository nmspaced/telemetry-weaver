<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\DecoratedService;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessagingSystem;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableMessageBusMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableSendersLocator;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\WorkerFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Runtime\ProcessMetrics;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Runtime\BoundaryFlush;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Prepends the dispatch middleware to every bus and wraps the senders locator.
 *
 * Runs before `MessengerPass`, which builds the middleware chains from `<busId>.middleware`.
 */
final readonly class MessengerInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string BUS_TAG = 'messenger.bus';

    private const string SENDERS_LOCATOR_ID = 'messenger.senders_locator';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)->requires('symfony/messenger', MessageBusInterface::class);

        if ($gate->isClosed()) {
            return;
        }

        $container
            ->register(WorkerFlushSubscriber::class, WorkerFlushSubscriber::class)
            ->setArgument('$flusher', new Reference(BoundaryFlush::class))
            ->addTag('kernel.event_subscriber');

        if ($container->hasDefinition(ProcessMetrics::class)) {
            $container->getDefinition(ProcessMetrics::class)->addTag('kernel.event_listener', [
                'event' => WorkerRunningEvent::class,
                'method' => 'register',
                'priority' => 8192,
            ]);
        }

        if ($gate->carriesContext('messenger')->isClosed()) {
            return;
        }

        foreach (\array_keys($container->findTaggedServiceIds(self::BUS_TAG)) as $busId) {
            $this->prepend($container, $busId);
        }

        $this->decorateSenders($container);
    }

    /**
     * One PRODUCER span per transport: Messenger's send events fire once per message, not per sender.
     */
    private function decorateSenders(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SENDERS_LOCATOR_ID)) {
            return;
        }

        $innerId = DecoratedService::innerId(self::SENDERS_LOCATOR_ID);

        $container
            ->register(DecoratedService::id(self::SENDERS_LOCATOR_ID), TraceableSendersLocator::class)
            ->setDecoratedService(self::SENDERS_LOCATOR_ID, $innerId)
            ->setArgument('$delegate', new Reference($innerId))
            ->setArgument('$messengerTelemetry', new Reference(MessengerTelemetry::class))
            ->setArgument('$propagation', new Reference(Propagation::class))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->setArgument('$systems', new Reference(MessagingSystem::class));
    }

    private function prepend(ContainerBuilder $container, string $busId): void
    {
        $parameter = \sprintf('%s.middleware', $busId);

        if (!$container->hasParameter($parameter)) {
            return;
        }

        $middleware = $container->getParameter($parameter);

        if (!\is_array($middleware)) {
            return;
        }

        $middlewareId = DecoratedService::siblingId($busId, 'middleware');

        $container
            ->register($middlewareId, TraceableMessageBusMiddleware::class)
            ->setArgument('$messengerTelemetry', new Reference(MessengerTelemetry::class))
            ->setArgument('$busId', $busId)
            ->setArgument('$consumption', new Reference(MessengerConsumption::class));

        \array_unshift($middleware, ['id' => $middlewareId]);

        $container->setParameter($parameter, $middleware);
    }
}
