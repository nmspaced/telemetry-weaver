<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessagingSystem;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerMetricAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `messaging.system` resolution: a transport the locator knows about names its broker, on the
 * span and both metrics; an unknown one falls back to the framework's own name rather than
 * guessing. Span structure and metrics otherwise live in {@see MessengerTelemetryTest} and
 * {@see MessengerTelemetryMetricsTest}.
 */
final class MessengerTelemetrySystemTest extends MessengerTelemetryTestCase
{
    /**
     * `messaging.system` names the broker when the transport says which one it is. The
     * resolver looks only at the transport object — no DSN, no connection — and here is
     * told that the in-memory transport is RabbitMQ, which is what proves the send, the
     * process span and both metrics all take the value from it.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aKnownTransportReportsItsBrokerAsTheMessagingSystem(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $systems = new MessagingSystem(new Container(['async' => $this->transport]), [
            InMemoryTransport::class => 'rabbitmq',
        ]);
        $bus = $this->asyncBus($telemetry, $dispatcher, systems: $systems);

        $bus->dispatch(new SampleMessage('otel'));
        $this->work($telemetry, $dispatcher, $bus);
        $this->reader->collect();

        self::assertSame(
            'rabbitmq',
            MessengerSpanAssertions::spanNamed($this->spans, 'send async')->getAttributes()->get('messaging.system'),
        );
        self::assertSame(
            'rabbitmq',
            MessengerSpanAssertions::spanNamed($this->spans, 'process async')->getAttributes()->get('messaging.system'),
        );
        self::assertSame(
            'rabbitmq',
            MessengerMetricAssertions::counter($this->metrics, 'messaging.client.sent.messages')->attributes->get(
                'messaging.system',
            ),
        );
        self::assertSame(
            'rabbitmq',
            MessengerMetricAssertions::histogram($this->metrics, 'messaging.process.duration')->attributes->get(
                'messaging.system',
            ),
        );
    }

    /**
     * An unknown transport, or one the receiver locator cannot produce, keeps the
     * framework fallback rather than a guess from its name.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anUnknownTransportFallsBackToTheFramework(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $systems = new MessagingSystem(new Container([]), [InMemoryTransport::class => 'rabbitmq']);
        $bus = $this->asyncBus($telemetry, $dispatcher, systems: $systems);

        $bus->dispatch(new SampleMessage('otel'));
        $this->work($telemetry, $dispatcher, $bus);

        self::assertSame(
            'rabbitmq',
            MessengerSpanAssertions::spanNamed($this->spans, 'send async')->getAttributes()->get('messaging.system'),
        );
        self::assertSame(
            'symfony',
            MessengerSpanAssertions::spanNamed($this->spans, 'process async')->getAttributes()->get('messaging.system'),
            'the receiver is not in the locator, so nothing is known about it',
        );
        self::assertSame([], $this->logger->messages());
    }
}
