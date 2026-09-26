<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedActivations;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;

/** A long-running worker gives every message its own trace and leaves no context behind. */
#[CoversClass(MessengerConsumption::class)]
final class MessengerWorkerIsolationTest extends MessengerTelemetryTestCase
{
    private const int MESSAGES = 1_000;

    /** @throws \Throwable|\ReflectionException */
    #[Test]
    public function aLongRunGivesEveryMessageItsOwnTraceAndLeavesNoActivationBehind(): void
    {
        $activationsBefore = self::activatedOwners();

        $telemetry = $this->telemetry();
        $dispatcher = new EventDispatcher();
        $bus = $this->asyncBus($telemetry, $dispatcher);

        for ($i = 0; $i < self::MESSAGES; ++$i) {
            $bus->dispatch(new SampleMessage('message-' . $i));
        }

        $this->work($telemetry, $dispatcher, $bus);

        $processed = MessengerSpanAssertions::spansNamed($this->spans, 'process async');
        self::assertCount(self::MESSAGES, $processed);

        $traces = \array_unique(\array_map(
            static fn(ImmutableSpan $span): string => $span->getContext()->getTraceId(),
            $processed,
        ));
        self::assertCount(self::MESSAGES, $traces, "a message inherited another message's trace");

        self::assertNull(Context::storage()->scope(), 'the worker left a scope on the stack');
        self::assertSame(
            $activationsBefore,
            self::activatedOwners(),
            'an operation stayed activated after its message was processed',
        );
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function anOperationLeftUnfinishedByAHandlerEndsWithItsMessage(): void
    {
        $consumption = $this->consumption($this->telemetry());
        $application = TelemetryFactory::tracing(
            new SpanOpener(
                $this->tracers->getTracer('application'),
                Context::storage(),
                new InstrumentationFailureReporter($this->logger),
            ),
        );
        $envelope = new Envelope(new SampleMessage('first'));
        $held = null;

        $consumption->run($envelope, 'async', static function () use ($application, $envelope, &$held): Envelope {
            $held = $application->operation('unfinished')->baggage(['tenant' => 'first'])->start();

            return $envelope;
        });

        self::assertInstanceOf(RunningOperation::class, $held);
        self::assertFalse($held->span()->isRecording());
        self::assertNull(Context::storage()->scope(), 'the next message would start inside the handler context');
        self::assertNull(Baggage::getCurrent()->getValue('tenant'));
        self::assertSame(
            ['unfinished', 'process async'],
            \array_map(static fn(ImmutableSpan $span): string => $span->getName(), $this->spans->getSpans()),
        );
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function anOperationLeftUnfinishedByAFailingHandlerEndsWithItsMessage(): void
    {
        $consumption = $this->consumption($this->telemetry());
        $application = TelemetryFactory::tracing(
            new SpanOpener(
                $this->tracers->getTracer('application'),
                Context::storage(),
                new InstrumentationFailureReporter($this->logger),
            ),
        );
        $failure = new \RuntimeException('handler exploded');
        $held = null;

        try {
            $consumption->run(
                new Envelope(new SampleMessage('first')),
                'async',
                /** @throws \RuntimeException */
                static function () use ($application, $failure, &$held): never {
                    $held = $application->operation('unfinished')->baggage(['tenant' => 'first'])->start();

                    throw $failure;
                },
            );
            self::fail('The handler failure must escape');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertInstanceOf(RunningOperation::class, $held);
        self::assertFalse($held->span()->isRecording());
        self::assertNull(Context::storage()->scope());
        self::assertNull(Baggage::getCurrent()->getValue('tenant'));
        $process = MessengerSpanAssertions::spansNamed($this->spans, 'process async')[0] ?? self::fail(
            'no consumer span',
        );
        self::assertSame(StatusCode::STATUS_ERROR, $process->getStatus()->getCode());
        self::assertSame([], $this->logger->messages());
    }

    /**
     * How many spans this process still holds activated.
     *
     * @throws \ReflectionException
     */
    private static function activatedOwners(): int
    {
        $owners = new \ReflectionProperty(OwnedActivations::class, 'owners');

        /** @var \WeakMap<object, \WeakReference<OwnedSpan>>|null $map */
        $map = $owners->getValue();

        return $map === null ? 0 : \count($map);
    }
}
