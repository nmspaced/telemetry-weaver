<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\DiagnosticsLogger;
use Nmspaced\TelemetryWeaver\Internal\Operation\CallbackContext;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\DisabledTelemetryFactory;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoBaggage;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** A switched-off bundle keeps application code working and observes nothing. */
#[CoversClass(DisabledTelemetryFactory::class)]
#[CoversClass(DefaultTelemetry::class)]
#[CoversClass(NoBaggage::class)]
#[CoversClass(NoOpSpanOpener::class)]
#[CoversClass(CallbackContext::class)]
#[CoversClass(DiagnosticsLogger::class)]
final class DisabledTelemetryTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function callbacksStillRunAndSeeNoBaggage(): void
    {
        $telemetry = new DisabledTelemetryFactory()->scope('app');

        $seen = $telemetry
            ->operation('checkout')
            ->baggage(['tenant' => 'first'])
            ->run(
                /** @return array<non-empty-string, string> */
                static fn(OperationContext $context): array => $context->baggage(),
            );

        self::assertSame([], $seen);
    }

    /** @throws \Throwable */
    #[Test]
    public function boundariesWithoutASpanStillRun(): void
    {
        $telemetry = DefaultTelemetry::disabled();

        self::assertSame(
            42,
            $telemetry
                ->boundary('cache.get')
                ->withoutSpan()
                ->run(static fn(): int => 42),
        );
        self::assertSame(7, $telemetry->execution('request')->run(static fn(): int => 7));
    }

    #[Test]
    public function aScopeNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DisabledTelemetryFactory()->scope('');
    }

    #[Test]
    public function turnedOffDiagnosticsGoNowhere(): void
    {
        $logger = new RecordingLogger();

        self::assertInstanceOf(NullLogger::class, DiagnosticsLogger::create($logger, false));
        self::assertSame($logger, DiagnosticsLogger::create($logger, true));
    }
}
