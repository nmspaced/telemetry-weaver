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

/**
 * MessengerPass turns the parameter `<busId>.middleware` into the bus's middleware
 * chain at before-optimization priority 0. Everything this pass does has to be in that
 * parameter before then, so the test stands the parameter up the way FrameworkBundle
 * does and checks what came out.
 */
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
        // Argument 1: named arguments are resolved to positions by the compiler.
        self::assertSame(self::BUS_ID, $definition->getArgument(1));
    }

    /**
     * A message routed to two transports has to produce two spans, so the senders are
     * wrapped one by one where the locator hands them out.
     *
     * @throws \Throwable
     */
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

    /**
     * The middleware and the sender decorator carry the producer's metrics as well as its
     * spans — the dispatch and send durations and the sent counter are recorded nowhere
     * else. So tracing alone being off is not a reason to stop wrapping.
     *
     * @throws \Throwable
     */
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

    /**
     * The middleware and the sender decorator also carry the message's trace and baggage.
     * The global signal switches decide what is recorded, not whether that context
     * travels, so with both of them off the wrapping stays.
     *
     * @throws \Throwable
     */
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
