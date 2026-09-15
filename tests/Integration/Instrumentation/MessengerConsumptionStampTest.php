<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableMessageBusMiddleware;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerMetricAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class MessengerConsumptionStampTest extends MessengerTelemetryTestCase
{
    /** @return iterable<string, array{list<StampInterface>, string}> */
    public static function stamps(): iterable
    {
        yield 'received only' => [[new ReceivedStamp('sync')], 'sync'];
        yield 'worker only' => [[new ConsumedByWorkerStamp()], 'unknown'];
        yield 'both' => [[new ReceivedStamp('async'), new ConsumedByWorkerStamp()], 'async'];
        yield 'empty destination' => [[new ReceivedStamp('')], 'unknown'];
    }

    /**
     * @param list<StampInterface> $stamps
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('stamps')]
    public function eitherStampCreatesExactlyOneConsumer(array $stamps, string $destination): void
    {
        $telemetry = $this->telemetry();
        $calls = 0;
        $bus = new MessageBus([
            new TraceableMessageBusMiddleware($telemetry, 'bus', $this->consumption($telemetry)),
            new HandleMessageMiddleware(new HandlersLocator([
                SampleMessage::class => [static function () use (&$calls): void {
                    ++$calls;
                    self::assertTrue(Span::getCurrent()->getContext()->isValid());
                }],
            ])),
        ]);
        $ambient = Context::getRoot()->with(Context::createKey('caller'), 'ambient');
        $scope = $ambient->activate();
        try {
            $bus->dispatch(new Envelope(new SampleMessage('received'), $stamps));
            self::assertSame($ambient, Context::getCurrent());
        } finally {
            $scope->detach();
        }

        $this->reader->collect();
        self::assertSame(1, $calls);
        self::assertSame(['process ' . $destination], MessengerSpanAssertions::exportedNames($this->spans));
        $span = MessengerSpanAssertions::spanNamed($this->spans, 'process ' . $destination);
        self::assertSame(SpanKind::KIND_CONSUMER, $span->getKind());
        self::assertSame($destination, $span->getAttributes()->get('messaging.destination.name'));
        self::assertSame(1, MessengerMetricAssertions::histogram($this->metrics, 'messaging.process.duration')->count);
        self::assertNull(Context::storage()->scope());
        self::assertSame([], $this->logger->messages());
    }
}
