<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationTimer;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingHistogram;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DurationTimer::class)]
final class TimerTest extends TestCase
{
    private RecordingHistogram $histogram;

    private FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->histogram = new RecordingHistogram();
        $this->clock = new FrozenClock();
    }

    #[Test]
    public function recordsElapsedTimeInTheDeclaredUnit(): void
    {
        $timer = new DurationTimer($this->histogram, DurationUnit::Milliseconds, $this->clock);

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
        $timer = new DurationTimer($this->histogram, DurationUnit::Microseconds, $this->clock);

        $this->clock->advanceNanoseconds(1_500_000);
        $timer->stop();

        self::assertSame([[1_500.0, []]], $this->histogram->records);
    }

    #[Test]
    public function attributesReachTheHistogram(): void
    {
        $timer = new DurationTimer($this->histogram, DurationUnit::Milliseconds, $this->clock);

        $timer->stop(['db.system' => 'mysql']);

        self::assertSame([[0.0, ['db.system' => 'mysql']]], $this->histogram->records);
    }

    /**
     * Telemetry must not break the caller, so a repeated stop() cannot throw — but recording the same interval twice would skew the distribution worse than dropping it.
     */
    #[Test]
    public function stoppingTwiceRecordsOnce(): void
    {
        $timer = new DurationTimer($this->histogram, DurationUnit::Milliseconds, $this->clock);

        $timer->stop();

        $this->clock->advanceNanoseconds(5_000_000);
        $timer->stop();

        self::assertCount(1, $this->histogram->records);
    }
}
