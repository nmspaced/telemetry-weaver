<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeCounter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeGauge;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeUpDownCounter;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use PHPUnit\Event\NoPreviousThrowableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;

/**
 * `isEnabled()` on the fail-open instrument wrappers.
 *
 * It is the one instrument method that answers a question rather than records one, which is
 * why it needs its own file: everything else in {@see MetricInstrumentsTest} is about what a
 * name becomes on the wire. Callers are told to check it before building attributes, so it
 * sits on the hot path of anyone who took that advice — and a wrapper that let an exception
 * out of it would turn "is anyone listening?" into a failed request.
 */
#[CoversClass(SafeCounter::class)]
#[CoversClass(SafeGauge::class)]
#[CoversClass(SafeUpDownCounter::class)]
final class InstrumentStateTest extends PublicTelemetryTestCase
{
    /**
     * False on failure, never true: a caller that gets true builds attributes and then records
     * into an instrument that has already proven it throws.
     *
     * @throws NoPreviousThrowableException
     * @throws Exception
     */
    #[Test]
    public function aBrokenInstrumentAnswersIsEnabledWithFalseAndReports(): void
    {
        $failure = new \RuntimeException('instrument is gone');

        $counter = self::createStub(CounterInterface::class);
        $counter->method('isEnabled')->willThrowException($failure);

        // @mago-expect analysis:experimental-usage — the synchronous Gauge is stable in the metrics specification; only its PHP interface still carries the marker
        $gauge = self::createStub(GaugeInterface::class);
        $gauge->method('isEnabled')->willThrowException($failure);

        $upDown = self::createStub(UpDownCounterInterface::class);
        $upDown->method('isEnabled')->willThrowException($failure);

        self::assertFalse(new SafeCounter($counter, $this->reporter, 'app.counter')->isEnabled());
        self::assertFalse(new SafeGauge($gauge, $this->reporter, 'app.gauge')->isEnabled());
        self::assertFalse(new SafeUpDownCounter($upDown, $this->reporter, 'app.updown')->isEnabled());
        self::assertSame(3, $this->reporter->total());
    }

    /**
     * And the working case, so the wrapper is not answering false because it never asks.
     */
    #[Test]
    public function aWorkingInstrumentAnswersIsEnabledFromItsDelegate(): void
    {
        $metrics = $this->telemetry()->metrics();

        self::assertTrue($metrics->counter('app.counter')->isEnabled());
        self::assertTrue($metrics->gauge('app.gauge')->isEnabled());
        self::assertTrue($metrics->upDownCounter('app.updown')->isEnabled());
        $this->assertNoReports();
    }
}
