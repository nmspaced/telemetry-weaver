<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerMetricAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\MessengerSpanAssertions;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class MessengerErrorTypeTest extends MessengerTelemetryTestCase
{
    /** @return iterable<string, array{\Throwable, class-string}> */
    public static function failures(): iterable
    {
        $envelope = new Envelope(new SampleMessage('failed'));
        $cause = new \DomainException('domain failure');
        $single = new HandlerFailedException($envelope, ['handler' => $cause]);
        $multiple = new HandlerFailedException($envelope, [$cause, new \RuntimeException('another handler failed')]);

        yield 'plain' => [$cause, \DomainException::class];
        yield 'wrapped' => [$single, \DomainException::class];
        yield 'nested' => [new HandlerFailedException($envelope, [$single]), \DomainException::class];
        yield 'multiple' => [$multiple, HandlerFailedException::class];
        yield 'nested multiple' => [new HandlerFailedException($envelope, [$multiple]), HandlerFailedException::class];
        yield 'anonymous cause' => [
            new HandlerFailedException($envelope, [new class extends \RuntimeException {}]),
            \RuntimeException::class,
        ];
    }

    /**
     * @param class-string $expectedType
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('failures')]
    public function consumerClassifiesBothSignalsAndPreservesTheException(\Throwable $error, string $expectedType): void
    {
        $envelope = new Envelope(new SampleMessage('failed'));
        try {
            $this->consumption($this->telemetry())->run(
                $envelope,
                'async',
                /** @throws \Throwable */ static fn(): never => throw $error,
            );
            self::fail('the original exception must escape');
        } catch (\Throwable $throwable) {
            self::assertSame($error, $throwable);
        }

        $this->reader->collect();
        $span = MessengerSpanAssertions::spanNamed($this->spans, 'process async');
        self::assertSame($expectedType, $span->getAttributes()->get('error.type'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $event = $span->getEvents()[0] ?? self::fail('missing original exception event');
        self::assertSame($error::class, $event->getAttributes()->get('exception.type'));
        self::assertSame(
            $expectedType,
            MessengerMetricAssertions::histogram($this->metrics, 'messaging.process.duration')->attributes->get(
                'error.type',
            ),
        );
        self::assertNull(Context::storage()->scope());
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function classificationDoesNotDependOnTracingBeingEnabled(): void
    {
        $telemetry = new MessengerTelemetry(TelemetryFactory::create(
            $this->meters->getMeter('test'),
            NoOpSpanOpener::disabled(),
            new InstrumentationFailureReporter($this->logger),
        ));
        $envelope = new Envelope(new SampleMessage('failed'));
        $error = new HandlerFailedException($envelope, [new \DomainException('domain failure')]);
        try {
            $this->consumption($telemetry)->run(
                $envelope,
                'async',
                /** @throws \Throwable */ static fn(): never => throw $error,
            );
            self::fail('the original exception must escape');
        } catch (\Throwable $throwable) {
            self::assertSame($error, $throwable);
        }

        $this->reader->collect();
        self::assertSame([], MessengerSpanAssertions::exportedNames($this->spans));
        self::assertSame(
            \DomainException::class,
            MessengerMetricAssertions::histogram($this->metrics, 'messaging.process.duration')->attributes->get(
                'error.type',
            ),
        );
        self::assertNull(Context::storage()->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function dispatchAndSendClassifyTheSameFailure(): void
    {
        $telemetry = $this->telemetry();
        $message = new SampleMessage('failed');
        $error = new HandlerFailedException(new Envelope($message), [new \DomainException('domain failure')]);
        try {
            $telemetry->dispatch(
                'bus',
                $message,
                /** @throws \Throwable */ static fn(): mixed => $telemetry->send(
                    'send async',
                    [],
                    /** @throws \Throwable */ static fn(): never => throw $error,
                ),
            );
        } catch (\Throwable $throwable) {
            self::assertSame($error, $throwable);
        }

        $this->reader->collect();
        foreach (['send async', 'symfony.messenger.dispatch ' . SampleMessage::class] as $name) {
            self::assertSame(
                \DomainException::class,
                MessengerSpanAssertions::spanNamed($this->spans, $name)->getAttributes()->get('error.type'),
            );
        }

        self::assertSame(
            \DomainException::class,
            MessengerMetricAssertions::histogram(
                $this->metrics,
                'messaging.client.operation.duration',
            )->attributes->get('error.type'),
        );
        self::assertSame(
            \DomainException::class,
            MessengerMetricAssertions::counter($this->metrics, 'messaging.client.sent.messages')->attributes->get(
                'error.type',
            ),
        );
        self::assertNull(Context::storage()->scope());
    }

    /** @throws \Throwable */
    #[Test]
    public function anExplicitFailureTypeStillWins(): void
    {
        $message = new SampleMessage('failed');
        $error = new HandlerFailedException(new Envelope($message), [new \DomainException('domain failure')]);
        try {
            $this->telemetry()->dispatch(
                'bus',
                $message,
                /** @throws \Throwable */ static function (Span $span) use ($error): never {
                    $span->fail('application.failure');
                    throw $error;
                },
            );
        } catch (\Throwable $throwable) {
            self::assertSame($error, $throwable);
        }

        self::assertSame(
            'application.failure',
            MessengerSpanAssertions::spanNamed(
                $this->spans,
                'symfony.messenger.dispatch ' . SampleMessage::class,
            )->getAttributes()->get('error.type'),
        );
        self::assertNull(Context::storage()->scope());
    }
}
