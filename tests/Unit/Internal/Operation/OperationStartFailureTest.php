<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DiscardedDurations;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Durations;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\OperationPlan;
use Nmspaced\TelemetryWeaver\Internal\Operation\OperationStarter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoBaggage;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Telemetry that cannot start still lets the business work run, and says why. */
#[CoversClass(OperationStarter::class)]
#[CoversClass(OperationPlan::class)]
#[CoversClass(Durations::class)]
#[CoversClass(SafeMetrics::class)]
final class OperationStartFailureTest extends TestCase
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
    public function aFailingSpanOpenerCostsTheSpanNotTheWork(): void
    {
        $opener = $this->createStub(SpanOpenerInterface::class);
        $opener->method('open')->willThrowException(new \RuntimeException('tracer is gone'));

        $result = $this
            ->telemetry($opener)
            ->operation('checkout')
            ->run(static fn(): int => 42);

        self::assertSame(42, $result);
        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('Operation span start failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aDurationFromAnotherImplementationMeasuresNothing(): void
    {
        $foreign = new class implements Duration {};

        $result = DefaultTelemetry::disabled()
            ->operation('checkout')
            ->duration($foreign)
            ->run(static fn(): int => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function anOperationNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @var non-empty-string $empty the guard exists for callers that bypass the type */
        $empty = '';
        DefaultTelemetry::disabled()->operation($empty);
    }

    #[Test]
    public function anInstrumentNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @var non-empty-string $empty the guard exists for callers that bypass the type */
        $empty = '';
        DefaultTelemetry::disabled()->metrics()->duration($empty, DurationUnit::Seconds, [0.1]);
    }

    /** @throws \Throwable */
    private function telemetry(SpanOpenerInterface $opener): DefaultTelemetry
    {
        return new DefaultTelemetry(
            $opener,
            new SafeMetrics(new NoopMeter(), $this->reporter, new DiscardedDurations()),
            $this->reporter,
            new NoBaggage(),
        );
    }
}
