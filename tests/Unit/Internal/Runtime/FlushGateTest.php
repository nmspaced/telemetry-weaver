<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The boundary-skip/interval core of {@see FlushPolicy}. Per-signal SDK schedule delays live
 * in {@see FlushGateScheduleTest}.
 */
#[CoversClass(FlushPolicy::class)]
final class FlushGateTest extends TestCase
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
     * `atBoundary()` is reached from a shared HTTP worker's terminate and the Messenger
     * worker loop, and from nowhere else — every process that ends after one unit of work
     * goes through `atShutdown()` instead. So the first boundary is a worker's first unit of
     * work, and holding its telemetry back buys nothing while costing it its visibility.
     */
    #[Test]
    public function theFirstBoundaryInTheProcessFlushes(): void
    {
        $gate = FlushPolicy::every('metrics', 60_000, $this->clock);

        self::assertTrue($gate->shouldFlush());
    }

    #[Test]
    public function aBoundaryInsideTheIntervalIsSkipped(): void
    {
        $gate = FlushPolicy::every('metrics', 60_000, $this->clock);

        $gate->shouldFlush();

        $this->clock->advanceSeconds(59);

        self::assertFalse($gate->shouldFlush());
    }

    #[Test]
    public function aBoundaryAfterTheIntervalFlushesAgain(): void
    {
        $gate = FlushPolicy::every('metrics', 60_000, $this->clock);

        $gate->shouldFlush();

        $this->clock->advanceSeconds(61);

        self::assertTrue($gate->shouldFlush());
    }

    /**
     * A fresh instance must not restart the schedule — the state models the process, not the
     * object. A worker that rebuilds its container between units of work would otherwise
     * flush on every single boundary.
     */
    #[Test]
    public function stateSurvivesANewInstance(): void
    {
        FlushPolicy::every('metrics', 60_000, $this->clock)->shouldFlush();

        self::assertFalse(FlushPolicy::every('metrics', 60_000, $this->clock)->shouldFlush());
    }
}
