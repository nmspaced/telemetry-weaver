<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\TracingEventDispatcher;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderListener;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\ImmutableEventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

final class EventListenerRegistrationTest extends ContainerTestCase
{
    private const array ENABLED = ['instrumentation' => ['event_listeners' => ['traces' => true]]];

    /** @throws \Throwable */
    #[Test]
    public function offByDefault(): void
    {
        $container = $this->compile();

        self::assertFalse($container->getParameter('open_telemetry.instrumentation.event_listeners.traces'));
        self::assertSame(
            ['App\\'],
            $container->getParameter('open_telemetry.instrumentation.event_listeners.namespaces'),
        );
        self::assertNotInstanceOf(TracingEventDispatcher::class, $container->get('event_dispatcher'));
    }

    /** @throws \Throwable */
    #[Test]
    public function everyPlainDispatcherIsBuiltAsTheTracingOne(): void
    {
        $container = $this->compile(self::ENABLED, configure: static function (ContainerBuilder $container): void {
            $container->register('security.event_dispatcher.main', EventDispatcher::class);
        });

        self::assertInstanceOf(TracingEventDispatcher::class, $container->get('event_dispatcher'));
        self::assertInstanceOf(TracingEventDispatcher::class, $container->get('security.event_dispatcher.main'));
    }

    /** @throws \Throwable */
    #[Test]
    public function lazyListenersRegisteredAtCompileTimeAreStillCalled(): void
    {
        $container = $this->compile(self::ENABLED, configure: static function (ContainerBuilder $container): void {
            $container->addCompilerPass(new RegisterListenersPass(), PassConfig::TYPE_BEFORE_REMOVING);
            $container
                ->register(OrderListener::class, OrderListener::class)
                ->addTag('kernel.event_listener', ['event' => 'order.placed', 'method' => 'stop']);
        });

        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(TracingEventDispatcher::class, $dispatcher);

        // The event itself, not dispatch()'s result: older event-dispatcher-contracts type that as `object`.
        $event = new Event();
        $dispatcher->dispatch($event, 'order.placed');

        self::assertTrue($event->isPropagationStopped());
    }

    /** @throws \Throwable */
    #[Test]
    public function aDispatcherBuiltAnyOtherWayIsLeftAlone(): void
    {
        $container = $this->compile(self::ENABLED, configure: static function (ContainerBuilder $container): void {
            $container
                ->register('readonly_dispatcher', ImmutableEventDispatcher::class)
                ->setArguments([new Reference('event_dispatcher')]);
        });

        self::assertInstanceOf(ImmutableEventDispatcher::class, $container->get('readonly_dispatcher'));
    }

    /** @throws \Throwable */
    #[Test]
    public function theConfiguredNamespacesReachTheContainer(): void
    {
        $container = $this->compile([
            'instrumentation' => ['event_listeners' => ['traces' => true, 'namespaces' => ['Acme\\Billing\\']]],
        ]);

        self::assertSame(
            ['Acme\\Billing\\'],
            $container->getParameter('open_telemetry.instrumentation.event_listeners.namespaces'),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aDisabledBundleLeavesTheDispatcherAlone(): void
    {
        $container = $this->compile(['enabled' => false] + self::ENABLED);

        self::assertNotInstanceOf(TracingEventDispatcher::class, $container->get('event_dispatcher'));
    }
}
