<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableSender;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceContextStamp;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * A failing propagator costs the trace, never the request or the message.
 *
 * @see HttpParentContextTest::aThrowingPropagatorCostsTheTraceAndNotTheRequest for the
 *      incoming half, which needs the HTTP stack to show what it costs.
 */
#[CoversClass(OtelPropagation::class)]
#[CoversClass(TraceableSender::class)]
#[CoversClass(MessengerConsumption::class)]
#[CoversClass(RootTrace::class)]
final class PropagationFailureTest extends MessengerTelemetryTestCase
{
    private const string INCOMING_TRACE_ID = '11111111111111111111111111111111';

    private const string TRACEPARENT = '00-' . self::INCOMING_TRACE_ID . '-2222222222222222-01';

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('injectionModes')]
    public function aFailingInjectionStillSendsTheMessageExactlyOnce(string $mode): void
    {
        $sends = [];
        $delegate = new class($sends) implements SenderInterface {
            /** @param list<Envelope> $sends */
            public function __construct(
                private array &$sends,
            ) {}

            #[\Override]
            public function send(Envelope $envelope): Envelope
            {
                $this->sends[] = $envelope;

                return $envelope->with(new SentMarkerStamp());
            }
        };

        $sender = new TraceableSender(
            $delegate,
            $this->telemetry(),
            $this->propagation($mode),
            'async',
            new InstrumentationFailureReporter($this->logger),
        );

        $marker = new SentMarkerStamp();
        $message = new SampleMessage('payload');
        $envelope = new Envelope($message, [
            $marker,
            new TraceContextStamp(['traceparent' => self::TRACEPARENT]),
        ]);
        $sent = $sender->send($envelope);

        self::assertCount(1, $sends, 'the transport must be called exactly once');
        $delivered = $sends[0] ?? self::fail('the transport received no envelope');
        self::assertSame($message, $sent->getMessage());
        self::assertSame($marker, $delivered->last(SentMarkerStamp::class));
        self::assertSame([], $delivered->all(TraceContextStamp::class), 'the transport must not receive stale context');
        self::assertSame([], $sent->all(TraceContextStamp::class));
        self::assertNotNull($envelope->last(TraceContextStamp::class), 'the original envelope stays immutable');
        self::assertNotNull($sent->last(SentMarkerStamp::class), 'the transport result must reach the caller');
        self::assertCount($mode === 'empty' ? 0 : 1, $this->logger->records);
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('injectionModes')]
    public function aPropagationFailureDoesNotReplaceOrRetryTheTransportException(string $mode): void
    {
        $failure = new \RuntimeException('transport unavailable');
        $delegate = $this->createMock(SenderInterface::class);
        $delegate->expects(self::once())->method('send')->willThrowException($failure);
        $sender = new TraceableSender(
            $delegate,
            $this->telemetry(),
            $this->propagation($mode),
            'async',
            new InstrumentationFailureReporter($this->logger),
        );

        try {
            $sender->send(new Envelope(new SampleMessage('payload')));
            self::fail('the transport exception must reach the caller');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertCount($mode === 'empty' ? 0 : 1, $this->logger->records);
        self::assertNull(Context::storage()->scope(), 'the send scope must be released');
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailingExtractionCostsTheIncomingEdgeAndNotTheConsumerSpan(): void
    {
        $propagation = $this->createStub(Propagation::class);
        $propagation->method('extract')->willThrowException(new \RuntimeException('propagation unavailable'));
        $consumption = $this->consumption($this->telemetry(), propagation: $propagation);
        $envelope = new Envelope(new SampleMessage('payload'), [
            new TraceContextStamp(['traceparent' => self::TRACEPARENT]),
        ]);

        $handled = $consumption->run($envelope, 'async', static fn(): Envelope => $envelope);

        self::assertSame($envelope, $handled);
        $span = MessengerSpanAssertions::spanNamed($this->spans, 'process async');
        self::assertFalse($span->getParentContext()->isValid());
        self::assertNotSame(self::INCOMING_TRACE_ID, $span->getContext()->getTraceId());
        self::assertSame([], $span->getLinks(), 'an unreadable carrier is not a link either');
        self::assertCount(1, $this->logger->records);
        self::assertNull(Context::storage()->scope());
    }

    /** @return iterable<string, array{string}> */
    public static function injectionModes(): iterable
    {
        yield 'SDK propagator throws' => ['sdk'];
        yield 'replacement propagation throws' => ['port'];
        yield 'no outgoing context' => ['empty'];
    }

    /** @throws \Throwable */
    private function propagation(string $mode): Propagation
    {
        if ($mode === 'sdk') {
            $propagator = $this->createStub(TextMapPropagatorInterface::class);
            $propagator->method('inject')->willThrowException(new \RuntimeException('propagator unavailable'));

            return new OtelPropagation(
                $propagator,
                Context::storage(),
                new InstrumentationFailureReporter($this->logger),
            );
        }

        $propagation = $this->createStub(Propagation::class);

        if ($mode === 'port') {
            $propagation->method('injectCurrent')->willThrowException(new \RuntimeException('propagation unavailable'));

            return $propagation;
        }

        $propagation->method('injectCurrent')->willReturn([]);

        return $propagation;
    }
}
