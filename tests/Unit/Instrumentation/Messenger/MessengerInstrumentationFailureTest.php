<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerConsumption;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\MessengerWorkerSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation\SampleMessage;
use OpenTelemetry\API\Metrics\CounterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/** Telemetry that breaks while a worker consumes must cost the measurement, never the message. */
#[CoversClass(MessengerConsumption::class)]
#[CoversClass(MessengerWorkerSubscriber::class)]
final class MessengerInstrumentationFailureTest extends TestCase
{
    private RecordingLogger $logger;

    private InstrumentationFailureReporter $reporter;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->reporter = new InstrumentationFailureReporter($this->logger);
    }

    /** @throws \Throwable */
    #[Test]
    public function theHandlerStillRunsWhenItsOperationCannotStart(): void
    {
        $envelope = new Envelope(new SampleMessage('first'));
        $consumption = new MessengerConsumption(
            $this->brokenTelemetry(),
            $this->createStub(Propagation::class),
            $this->reporter,
        );
        $handled = false;

        $result = $consumption->run($envelope, 'async', static function () use ($envelope, &$handled): Envelope {
            $handled = true;

            return $envelope;
        });

        self::assertTrue($handled);
        self::assertSame($envelope, $result);
        self::assertStringContainsString('Messenger consumption instrumentation failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aDeliveryThatCannotBeCountedIsReportedNotThrown(): void
    {
        $subscriber = new MessengerWorkerSubscriber($this->brokenTelemetry(), $this->reporter);

        $subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope(new SampleMessage('first')), 'async'));

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('Messenger delivery measurement failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    private function brokenTelemetry(): MessengerTelemetry
    {
        $counter = $this->createStub(CounterInterface::class);
        $counter->method('add')->willThrowException(new \RuntimeException('meter is gone'));
        $metrics = $this->createStub(Metrics::class);
        $metrics->method('counter')->willReturn($counter);
        $telemetry = $this->createStub(BoundaryTelemetry::class);
        $telemetry->method('metrics')->willReturn($metrics);
        $telemetry->method('execution')->willThrowException(new \RuntimeException('tracer is gone'));

        return new MessengerTelemetry($telemetry);
    }
}
