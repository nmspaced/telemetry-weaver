<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Diagnostics;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\RateLimiter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    #[Test]
    public function passesTheBurstThenSuppresses(): void
    {
        $limiter = new RateLimiter(burst: 3, minIntervalSeconds: 60.0, clock: new FrozenClock());

        self::assertTrue($limiter->allow());
        self::assertTrue($limiter->allow());
        self::assertTrue($limiter->allow());
        self::assertFalse($limiter->allow(), 'fourth event is suppressed');
        self::assertFalse($limiter->allow());
    }

    /**
     * Regression: the interval used to be added to ClockInterface::now()'s
     * nanoseconds as if it were seconds, so a 60.0 pause lasted 60
     * nanoseconds and no suppression ever happened.
     */
    #[Test]
    public function intervalIsMeasuredInSecondsNotNanoseconds(): void
    {
        $clock = new FrozenClock();
        $limiter = new RateLimiter(burst: 1, minIntervalSeconds: 60.0, clock: $clock);

        self::assertTrue($limiter->allow());

        $clock->advanceSeconds(59.0);
        self::assertFalse($limiter->allow(), 'pause has not elapsed after 59 seconds');

        $clock->advanceSeconds(2.0);
        self::assertTrue($limiter->allow(), 'event passes again after 61 seconds');
    }

    #[Test]
    public function countsSuppressedEventsToo(): void
    {
        $limiter = new RateLimiter(burst: 1, minIntervalSeconds: 60.0, clock: new FrozenClock());

        $limiter->allow();
        $limiter->allow();
        $limiter->allow();

        self::assertSame(3, $limiter->total());
    }
}
