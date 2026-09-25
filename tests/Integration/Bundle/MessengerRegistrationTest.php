<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableMessageBusMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableSendersLocator;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

/** The Messenger pass puts the instrumentation first in every bus's middleware chain. */
final class MessengerRegistrationTest extends ContainerTestCase
{
    private const string BUS_ID = 'messenger.bus.commands';

    /** @throws \Throwable */
    #[Test]
    public function theMiddlewareIsPrependedToEveryBus(): void
    {
        $container = $this->compile(configure: self::withBus(...));

        /** @var list<array{id: string}> $middleware */
        $middleware = $container->getParameter(self::BUS_ID . '.middleware');

        $first = $middleware[0] ?? Assert::fail('middleware chain is empty');
        self::assertSame(
            self::BUS_ID . '.open_telemetry_middleware',
            $first['id'],
            'the dispatch span has to wrap the other middleware, not sit inside them',
        );
        self::assertSame(
            ['validation', 'send_message', 'handle_message'],
            \array_column(\array_slice($middleware, 1), 'id'),
            'the existing chain must keep its order',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function theMiddlewareServiceKnowsWhichBusItBelongsTo(): void
    {
        $container = $this->compile(configure: self::withBus(...));

        $definition = $container->getDefinition(self::BUS_ID . '.open_telemetry_middleware');
        self::assertSame(TraceableMessageBusMiddleware::class, $definition->getClass());
        self::assertSame(self::BUS_ID, $definition->getArgument(1));
    }

    /** @throws \Throwable */
    #[Test]
    public function everySenderIsWrappedThroughTheLocator(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            self::withBus($container);
            $container
                ->register('messenger.senders_locator', SendersLocator::class)
                ->setArguments([[], new Definition(ServiceLocator::class, [[]])]);
        });

        $locator = $container->get('messenger.senders_locator');
        self::assertInstanceOf(TraceableSendersLocator::class, $locator);
    }

    /** @throws \Throwable */
    #[Test]
    public function disablingOnlyMessengerTracingKeepsTheMiddlewareForTheMetrics(): void
    {
        $container = $this->compile(
            ['instrumentation' => ['messenger' => ['traces' => false]]],
            configure: self::withBus(...),
        );

        /** @var list<array{id: string}> $middleware */
        $middleware = $container->getParameter(self::BUS_ID . '.middleware');
        $first = $middleware[0] ?? Assert::fail('middleware chain is empty');
        self::assertSame(self::BUS_ID . '.open_telemetry_middleware', $first['id']);
    }

    /** @throws \Throwable */
    #[Test]
    public function disablingBothMessengerSignalsRemovesTheMiddleware(): void
    {
        $container = $this->compile(
            ['instrumentation' => ['messenger' => ['traces' => false, 'metrics' => false]]],
            configure: self::withBus(...),
        );

        /** @var list<array{id: string}> $middleware */
        $middleware = $container->getParameter(self::BUS_ID . '.middleware');
        self::assertSame(['validation', 'send_message', 'handle_message'], \array_column($middleware, 'id'));
    }

    /** @throws \Throwable */
    #[Test]
    public function turningOffBothGlobalSignalsKeepsTheMiddlewareForPropagation(): void
    {
        $container = $this->compile(
            ['traces' => ['enabled' => false], 'metrics' => ['enabled' => false]],
            configure: self::withBus(...),
        );

        /** @var list<array{id: string}> $middleware */
        $middleware = $container->getParameter(self::BUS_ID . '.middleware');
        $first = $middleware[0] ?? Assert::fail('middleware chain is empty');
        self::assertSame(self::BUS_ID . '.open_telemetry_middleware', $first['id']);
    }

    /** Stands up a bus the way FrameworkBundle does: a tagged service plus the parameter. */
    private static function withBus(ContainerBuilder $container): void
    {
        $container->register(self::BUS_ID, MessageBus::class)->addArgument([])->addTag('messenger.bus');
        $container->setParameter(self::BUS_ID . '.middleware', [
            ['id' => 'validation'],
            ['id' => 'send_message'],
            ['id' => 'handle_message'],
        ]);
    }
}
