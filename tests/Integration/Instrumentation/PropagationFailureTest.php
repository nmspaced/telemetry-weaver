<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceableSender;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Fail-open, closed on the propagation boundary.
 *
 * A propagator is the one part of the pipeline that runs before the application's work
 * rather than around it, so an exception out of it does not degrade telemetry — it
 * replaces the work. The sender never reached its transport and the message was lost;
 * the HTTP server's extraction left `kernel.request` and turned the request into a 500.
 * Both entry points now go through the adapter's own fail-open path.
 *
 * @see HttpParentContextTest::aThrowingPropagatorCostsTheTraceAndNotTheRequest for the
 *      incoming half, which needs the HTTP stack to show what it costs.
 */
#[CoversClass(OtelPropagation::class)]
#[CoversClass(TraceableSender::class)]
final class PropagationFailureTest extends MessengerTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function aFailingInjectionStillSendsTheMessageExactlyOnce(): void
    {
        $propagator = $this->createStub(TextMapPropagatorInterface::class);
        $propagator->method('inject')->willThrowException(new \RuntimeException('propagator unavailable'));

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
            new OtelPropagation($propagator, Context::storage(), new InstrumentationFailureReporter($this->logger)),
            'async',
        );

        $sent = $sender->send(new Envelope(new SampleMessage('payload')));

        self::assertCount(1, $sends, 'the transport must be called exactly once');
        self::assertNotNull($sent->last(SentMarkerStamp::class), 'the transport result must reach the caller');
        self::assertNotSame([], $this->logger->records, 'a swallowed telemetry failure must still be reported');
    }
}
