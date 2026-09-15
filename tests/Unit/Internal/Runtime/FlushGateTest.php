<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushPolicy;
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
     * A process that ends on its first boundary — every FPM request, every one-shot command — is served by the SDK's shutdown hook. Only a worker reaches a second boundary.
     */
    #[Test]
    public function theFirstBoundaryInTheProcessIsSkipped(): void
    {
        $gate = new FlushPolicy('metrics', 60_000, $this->clock);

        self::assertFalse($gate->shouldFlush());
    }

    #[Test]
    public function theSecondBoundaryFlushes(): void
    {
        $gate = new FlushPolicy('metrics', 60_000, $this->clock);

        $gate->shouldFlush();

        self::assertTrue($gate->shouldFlush());
    }

    #[Test]
    public function aBoundaryInsideTheIntervalIsSkipped(): void
    {
        $gate = new FlushPolicy('metrics', 60_000, $this->clock);

        $gate->shouldFlush();
        $gate->shouldFlush();

        $this->clock->advanceSeconds(59);

        self::assertFalse($gate->shouldFlush());
    }

    #[Test]
    public function aBoundaryAfterTheIntervalFlushesAgain(): void
    {
        $gate = new FlushPolicy('metrics', 60_000, $this->clock);

        $gate->shouldFlush();
        $gate->shouldFlush();

        $this->clock->advanceSeconds(61);

        self::assertTrue($gate->shouldFlush());
    }

    /**
     * A fresh instance must not restart the schedule — the state models the process, not the object.
     */
    #[Test]
    public function stateSurvivesANewInstance(): void
    {
        new FlushPolicy('metrics', 60_000, $this->clock)->shouldFlush();

        self::assertTrue(new FlushPolicy('metrics', 60_000, $this->clock)->shouldFlush());
    }
}
