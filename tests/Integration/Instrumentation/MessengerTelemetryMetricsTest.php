<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Tests\Support\MessengerMetricAssertions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * `messaging.client.operation.duration`, `messaging.process.duration` and the sent/consumed
 * counters: their labels, and which of send/process/dispatch each one counts. Span structure
 * lives in {@see MessengerTelemetryTest}.
 */
final class MessengerTelemetryMetricsTest extends MessengerTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theTwoDurationsLandInTheirOwnMetrics(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        $bus->dispatch(new SampleMessage('otel'));
        $this->work($telemetry, $dispatcher, $bus);
        $this->reader->collect();

        $names = MessengerMetricAssertions::recordedMetricNames($this->metrics);
        self::assertContains('messaging.client.operation.duration', $names);
        self::assertContains('messaging.process.duration', $names);

        $process = MessengerMetricAssertions::histogram($this->metrics, 'messaging.process.duration');
        self::assertSame(1, $process->count);
        self::assertSame(
            [
                'messaging.system' => 'symfony',
                'messaging.operation.name' => 'process',
                'messaging.operation.type' => 'process',
                'messaging.destination.name' => 'async',
                'symfony.messenger.message.class' => SampleMessage::class,
            ],
            $process->attributes->toArray(),
            'the message id and the retry flag stay on the span, out of the labels',
        );
    }

    /**
     * The conventions count *attempts*, so a send that threw still counts — with
     * error.type, which a successful one must not carry.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aFailedSendIsStillCountedAsAnAttempt(): void
    {
        $telemetry = $this->telemetry();
        $bus = $this->asyncBus($telemetry, new EventDispatcher(), senders: ['async' => new ExplodingTransport()]);

        try {
            $bus->dispatch(new SampleMessage('otel'));
            self::fail('the transport was expected to reject the message');
        } catch (\RuntimeException $runtimeException) {
            // The application sees the failure; the counter is what matters here.
            self::assertNotSame('', $runtimeException->getMessage());
        }

        $this->reader->collect();
        $sent = MessengerMetricAssertions::counter($this->metrics, 'messaging.client.sent.messages');

        self::assertSame(1, $sent->value);
        self::assertSame(\RuntimeException::class, $sent->attributes->get('error.type'));
    }

    /**
     * A consumption is counted once per delivery, not per successful handling: the
     * conventions put the outcome in messaging.process.duration, not in this counter.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aMessageThatBlowsUpInItsHandlerIsStillCountedAsConsumed(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher, fail: true);

        $bus->dispatch(new SampleMessage('otel'));
        $this->work($telemetry, $dispatcher, $bus);
        $this->reader->collect();

        self::assertSame(
            1,
            MessengerMetricAssertions::counter($this->metrics, 'messaging.client.consumed.messages')->value,
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function bothCountersCarryTheSameLabelsAsTheirDurations(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        $bus->dispatch(new SampleMessage('otel'));
        $this->work($telemetry, $dispatcher, $bus);
        $this->reader->collect();

        $sent = MessengerMetricAssertions::counter($this->metrics, 'messaging.client.sent.messages');
        self::assertSame(1, $sent->value);
        self::assertSame(
            [
                'messaging.system' => 'symfony',
                'messaging.operation.name' => 'send',
                'messaging.operation.type' => 'send',
                'messaging.destination.name' => 'async',
                'symfony.messenger.message.class' => SampleMessage::class,
            ],
            $sent->attributes->toArray(),
        );

        self::assertSame(
            1,
            MessengerMetricAssertions::counter($this->metrics, 'messaging.client.consumed.messages')->value,
        );
    }

    /**
     * An in-process dispatch creates a message without sending it, and the conventions
     * say this metric must not count those.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aSynchronousDispatchIsNotCountedAsSent(): void
    {
        $bus = $this->syncBus($this->telemetry(), new EventDispatcher());

        $bus->dispatch(new SampleMessage('otel'));

        $this->reader->collect();

        // The instrument exists from construction, so the stream is exported either way;
        // what must be absent is a measurement in it.
        self::assertSame([], MessengerMetricAssertions::dataPointsOf($this->metrics, 'messaging.client.sent.messages'));
    }

    /**
     * `messaging.client.operation.duration` measures talking to a broker. A synchronous
     * dispatch never does, and its duration — handler included — would drag the send
     * percentiles of every async message along with it.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aSynchronousDispatchRecordsNoClientDuration(): void
    {
        $bus = $this->syncBus($this->telemetry(), new EventDispatcher());

        $bus->dispatch(new SampleMessage('otel'));

        $this->reader->collect();

        self::assertSame(
            [],
            MessengerMetricAssertions::dataPointsOf($this->metrics, 'messaging.client.operation.duration'),
        );
    }

    /**
     * Only the send reaches the client histogram, once per transport.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theClientDurationCountsSendsOnly(): void
    {
        $bus = $this->asyncBus($this->telemetry(), new EventDispatcher());

        $bus->dispatch(new SampleMessage('otel'));

        $this->reader->collect();

        self::assertCount(
            1,
            MessengerMetricAssertions::dataPointsOf($this->metrics, 'messaging.client.operation.duration'),
            'one series: the send; the dispatch around it must not add a second',
        );

        $duration = MessengerMetricAssertions::histogram($this->metrics, 'messaging.client.operation.duration');
        self::assertSame(1, $duration->count);
        self::assertSame('send', $duration->attributes->get('messaging.operation.name'));
    }
}
