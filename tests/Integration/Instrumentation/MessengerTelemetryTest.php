<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceContextStamp;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerMetricAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Span structure across dispatch, send and process: kind, parenting, trace continuation,
 * multi-transport fan-out, ambient-span links and redelivery. Metrics live in {@see
 * MessengerTelemetryMetricsTest}; `messaging.system` resolution lives in {@see
 * MessengerTelemetrySystemTest}.
 */
final class MessengerTelemetryTest extends MessengerTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function anAsynchronousMessageIsDispatchedSentAndProcessedInOneTrace(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        $bus->dispatch(new SampleMessage('otel'));

        self::assertSame(
            ['send async', 'symfony.messenger.dispatch ' . SampleMessage::class],
            MessengerSpanAssertions::exportedNames($this->spans),
        );

        $this->work($telemetry, $dispatcher, $bus);

        $names = MessengerSpanAssertions::exportedNames($this->spans);
        self::assertContains('process async', $names);

        $dispatch = MessengerSpanAssertions::spanNamed(
            $this->spans,
            'symfony.messenger.dispatch ' . SampleMessage::class,
        );
        $send = MessengerSpanAssertions::spanNamed($this->spans, 'send async');
        $process = MessengerSpanAssertions::spanNamed($this->spans, 'process async');

        self::assertSame(SpanKind::KIND_INTERNAL, $dispatch->getKind());
        self::assertSame(SpanKind::KIND_PRODUCER, $send->getKind());
        self::assertSame(SpanKind::KIND_CONSUMER, $process->getKind());

        self::assertSame(
            $dispatch->getContext()->getTraceId(),
            $process->getContext()->getTraceId(),
            'the consumer must continue the trace of the producer',
        );
        self::assertSame(
            $send->getContext()->getSpanId(),
            $process->getParentContext()->getSpanId(),
            'the process span belongs under the send that produced the message',
        );
        self::assertSame([], $process->getLinks(), 'with no ambient span there is nothing else to link');
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function aSynchronousMessageGetsOneSpanAndNoStamp(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->syncBus($telemetry, $dispatcher);

        $envelope = $bus->dispatch(new SampleMessage('otel'));

        self::assertSame(
            ['symfony.messenger.dispatch ' . SampleMessage::class],
            MessengerSpanAssertions::exportedNames($this->spans),
        );
        self::assertNull(
            $envelope->last(TraceContextStamp::class),
            'a message that never reaches a transport has nothing to propagate',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function theDispatchSpanCarriesTheBusAndTheMessageClass(): void
    {
        $telemetry = $this->telemetry();
        $bus = $this->syncBus($telemetry, new EventDispatcher());

        $bus->dispatch(new SampleMessage('otel'));

        self::assertSame(
            [
                'symfony.messenger.bus' => 'messenger.bus.commands',
                'symfony.messenger.message.class' => SampleMessage::class,
            ],
            MessengerSpanAssertions::spanNamed($this->spans, 'symfony.messenger.dispatch ' . SampleMessage::class)
                ->getAttributes()
                ->toArray(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailingHandlerMarksTheConsumerSpan(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher, fail: true);

        $bus->dispatch(new SampleMessage('otel'));
        $this->work($telemetry, $dispatcher, $bus);

        $process = MessengerSpanAssertions::spanNamed($this->spans, 'process async');
        self::assertSame(StatusCode::STATUS_ERROR, $process->getStatus()->getCode());
        $event = $process->getEvents()[0] ?? self::fail('missing exception event');
        self::assertSame('exception', $event->getName());
        self::assertArrayHasKey('error.type', $process->getAttributes()->toArray());
    }

    /** @throws \Throwable */
    #[Test]
    public function aMessageRoutedToTwoTransportsGetsASpanPerTransport(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $second = new InMemoryTransport();
        $bus = $this->asyncBus($telemetry, $dispatcher, senders: ['async' => $this->transport, 'other' => $second]);

        $bus->dispatch(new SampleMessage('otel'));

        self::assertSame(
            ['send async', 'send other', 'symfony.messenger.dispatch ' . SampleMessage::class],
            MessengerSpanAssertions::exportedNames($this->spans),
        );

        $async = MessengerSpanAssertions::spanNamed($this->spans, 'send async');
        $other = MessengerSpanAssertions::spanNamed($this->spans, 'send other');

        self::assertSame('async', $async->getAttributes()->get('messaging.destination.name'));
        self::assertSame('other', $other->getAttributes()->get('messaging.destination.name'));
        self::assertNotSame($async->getContext()->getSpanId(), $other->getContext()->getSpanId());

        self::assertNotSame(
            MessengerSpanAssertions::stampOf($this->transport),
            MessengerSpanAssertions::stampOf($second),
            'the two transports must not share one traceparent',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aConsumerInsideAnAmbientSpanKeepsItAsALink(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        $bus->dispatch(new SampleMessage('otel'));

        $loop = $this->tracers->getTracer('test')->spanBuilder('worker loop')->startSpan();
        $scope = $loop->activate();

        try {
            $this->work($telemetry, $dispatcher, $bus);
        } finally {
            $scope->detach();
            $loop->end();
        }

        $process = MessengerSpanAssertions::spanNamed($this->spans, 'process async');
        self::assertSame(
            MessengerSpanAssertions::spanNamed($this->spans, 'send async')->getContext()->getSpanId(),
            $process->getParentContext()->getSpanId(),
        );
        self::assertCount(1, $process->getLinks());
        $link = $process->getLinks()[0] ?? self::fail('missing ambient-span link');
        self::assertSame($loop->getContext()->getSpanId(), $link->getSpanContext()->getSpanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function aRedeliveryIsItsOwnDeliveryMarkedOnTheSpanOnly(): void
    {
        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        $bus->dispatch(new SampleMessage('first'));
        $bus->dispatch(new SampleMessage('retried'), [new RedeliveryStamp(1)]);
        $this->work($telemetry, $dispatcher, $bus);
        $this->reader->collect();

        $processes = MessengerSpanAssertions::spansNamed($this->spans, 'process async');
        $sends = MessengerSpanAssertions::spansNamed($this->spans, 'send async');

        self::assertCount(2, $processes);
        $firstProcess = $processes[0] ?? self::fail('missing first process span');
        $secondProcess = $processes[1] ?? self::fail('missing second process span');
        $secondSend = $sends[1] ?? self::fail('missing second send span');
        self::assertNull($firstProcess->getAttributes()->get('symfony.messenger.redelivery'));
        self::assertTrue($secondProcess->getAttributes()->get('symfony.messenger.redelivery'));
        self::assertSame($secondSend->getContext()->getSpanId(), $secondProcess->getParentContext()->getSpanId());
        self::assertNotSame($firstProcess->getContext()->getTraceId(), $secondProcess->getContext()->getTraceId());

        $processDuration = MessengerMetricAssertions::histogram($this->metrics, 'messaging.process.duration');
        self::assertSame(2, $processDuration->count, 'one series for both deliveries');
        $consumed = MessengerMetricAssertions::counter($this->metrics, 'messaging.client.consumed.messages');
        self::assertSame(2, $consumed->value);
        self::assertNull($consumed->attributes->get('symfony.messenger.redelivery'));
    }
}
