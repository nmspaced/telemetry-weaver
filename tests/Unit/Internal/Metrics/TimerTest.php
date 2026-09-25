<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRuntime;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationTimer;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingHistogram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DurationTimer::class)]
final class TimerTest extends TestCase
{
    private RecordingHistogram $histogram;

    private FrozenClock $clock;

    private DurationRuntime $runtime;

    #[\Override]
    protected function setUp(): void
    {
        $this->histogram = new RecordingHistogram();
        $this->clock = new FrozenClock();
        $this->runtime = new DurationRuntime(
            new OtelDurationRecorder(),
            new InstrumentationFailureReporter(new NullLogger()),
            $this->clock,
        );
    }

    #[Test]
    public function recordsElapsedTimeInTheDeclaredUnit(): void
    {
        $timer = DurationTimer::started($this->histogram, DurationUnit::Milliseconds, $this->runtime);

        $this->clock->advanceNanoseconds(1_500_000);
        $timer->stop();

        self::assertSame([[1.5, []]], $this->histogram->records);
    }

    /**
     * The same elapsed nanoseconds must land on a different scale — this is what keeps an APCu call out of a millisecond bucket.
     */
    #[Test]
    public function theSameElapsedTimeConvertsToTheUnitItWasGiven(): void
    {
        $timer = DurationTimer::started($this->histogram, DurationUnit::Microseconds, $this->runtime);

        $this->clock->advanceNanoseconds(1_500_000);
        $timer->stop();

        self::assertSame([[1_500.0, []]], $this->histogram->records);
    }

    /**
     * Paused time is not measured, and pausing or resuming twice changes nothing: the
     * lazy reads that use this call both from more than one path.
     */
    #[Test]
    public function pausedTimeIsLeftOut(): void
    {
        $timer = DurationTimer::started($this->histogram, DurationUnit::Milliseconds, $this->runtime);

        $this->clock->advanceNanoseconds(1_000_000);
        $timer->pause();
        $timer->pause();

        $this->clock->advanceNanoseconds(50_000_000);
        $timer->resume();
        $timer->resume();

        $this->clock->advanceNanoseconds(2_000_000);
        $timer->stop();

        self::assertSame([[3.0, []]], $this->histogram->records);
    }

    #[Test]
    public function aTimerStoppedWhilePausedRecordsWhatRanBefore(): void
    {
        $timer = DurationTimer::started($this->histogram, DurationUnit::Milliseconds, $this->runtime);

        $this->clock->advanceNanoseconds(1_000_000);
        $timer->pause();
        $this->clock->advanceNanoseconds(50_000_000);
        $timer->stop();

        self::assertSame([[1.0, []]], $this->histogram->records);
    }

    #[Test]
    public function attributesReachTheHistogram(): void
    {
        $timer = DurationTimer::started($this->histogram, DurationUnit::Milliseconds, $this->runtime);

        $timer->stop(['db.system' => 'mysql']);

        self::assertSame([[0.0, ['db.system' => 'mysql']]], $this->histogram->records);
    }

    /**
     * Telemetry must not break the caller, so a repeated stop() cannot throw — but recording the same interval twice would skew the distribution worse than dropping it.
     */
    #[Test]
    public function stoppingTwiceRecordsOnce(): void
    {
        $timer = DurationTimer::started($this->histogram, DurationUnit::Milliseconds, $this->runtime);

        $timer->stop();

        $this->clock->advanceNanoseconds(5_000_000);
        $timer->stop();

        self::assertCount(1, $this->histogram->records);
    }
}
