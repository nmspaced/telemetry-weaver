<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Per-signal SDK schedule delays for {@see FlushPolicy}. The boundary-skip/interval core lives
 * in {@see FlushGateTest}.
 */
#[CoversClass(FlushPolicy::class)]
final class FlushGateScheduleTest extends TestCase
{
    private FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        FlushPolicy::resetProcessState();
        $this->clock = new FrozenClock();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FlushPolicy::resetProcessState();
    }

    /**
     * The state is keyed by signal precisely so one signal's schedule cannot silence
     * another's: metrics flushing does not put logs inside an interval they never started.
     */
    #[Test]
    public function signalsKeepSeparateSchedules(): void
    {
        $metrics = FlushPolicy::every('metrics', 60_000, $this->clock);
        $logs = FlushPolicy::every('logs', 60_000, $this->clock);

        $metrics->shouldFlush();
        $this->clock->advanceSeconds(30);

        self::assertFalse($metrics->shouldFlush(), 'metrics is halfway through its own interval');
        self::assertTrue($logs->shouldFlush(), 'logs has an interval of its own and has not started it');
    }

    /**
     * With `autoFlush` off, the boundary is the only thing that drains a batch queue, so a
     * signal has to be drained on its own SDK schedule. Sixty seconds of spans would
     * overflow the queue under any real traffic.
     */
    #[Test]
    public function tracesFollowTheBatchSpanScheduleDelay(): void
    {
        $gate = FlushPolicy::onSdkSchedule('traces', $this->clock);
        $gate->shouldFlush();
        $gate->shouldFlush();

        $this->clock->advanceSeconds(4);
        self::assertFalse($gate->shouldFlush(), 'inside OTEL_BSP_SCHEDULE_DELAY');

        $this->clock->advanceSeconds(2);
        self::assertTrue($gate->shouldFlush(), 'past the 5 s default of OTEL_BSP_SCHEDULE_DELAY');
    }

    #[Test]
    public function logsFollowTheBatchLogRecordScheduleDelay(): void
    {
        $_SERVER['OTEL_BLRP_SCHEDULE_DELAY'] = '3000';

        try {
            $gate = FlushPolicy::onSdkSchedule('logs', $this->clock);
        } finally {
            unset($_SERVER['OTEL_BLRP_SCHEDULE_DELAY']);
        }

        $gate->shouldFlush();
        $gate->shouldFlush();

        $this->clock->advanceSeconds(2);
        self::assertFalse($gate->shouldFlush());

        $this->clock->advanceSeconds(2);
        self::assertTrue($gate->shouldFlush());
    }

    #[Test]
    public function metricsFollowTheMetricExportInterval(): void
    {
        $gate = FlushPolicy::onSdkSchedule('metrics', $this->clock);
        $gate->shouldFlush();
        $gate->shouldFlush();

        $this->clock->advanceSeconds(59);
        self::assertFalse($gate->shouldFlush());

        $this->clock->advanceSeconds(2);
        self::assertTrue($gate->shouldFlush());
    }
}
