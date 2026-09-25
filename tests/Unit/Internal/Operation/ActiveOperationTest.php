<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\NoopDuration;
use Nmspaced\TelemetryWeaver\Internal\Operation\ActiveOperation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOwner;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveOperation::class)]
final class ActiveOperationTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aFailingCorrelationReadIsReportedAsNoTrace(): void
    {
        $logger = new RecordingLogger();
        $owner = $this->createStub(SpanOwner::class);
        $owner->method('name')->willReturn('checkout');
        $owner->method('correlation')->willThrowException(new \RuntimeException('context is gone'));
        $baggage = $this->createStub(BaggageReader::class);
        $baggage->method('of')->willReturn(['tenant' => 'acme']);
        $operation = ActiveOperation::owning(
            $owner,
            new NoopDuration(),
            [],
            new InstrumentationFailureReporter($logger),
            $baggage,
        );

        self::assertNull($operation->correlation());
        self::assertSame([], $operation->baggage());
        self::assertSame(
            [
                'OpenTelemetry lifecycle: Operation correlation read failed at "checkout": context is gone (1 total in this process)',
                'OpenTelemetry lifecycle: Baggage read failed at "checkout": context is gone (2 total in this process)',
            ],
            $logger->messages(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aFinishedOperationHasNoCorrelation(): void
    {
        $owner = $this->createMock(SpanOwner::class);
        $owner->method('name')->willReturn('checkout');
        $owner->expects(self::never())->method('correlation');
        $operation = ActiveOperation::owning(
            $owner,
            new NoopDuration(),
            [],
            new InstrumentationFailureReporter(new RecordingLogger()),
            $this->createStub(BaggageReader::class),
        );

        $operation->finish();

        self::assertNull($operation->correlation());
    }
}
