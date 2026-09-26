<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRuntime;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationTimer;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMeasurement;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\Tests\Fake\BreakableClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingHistogram;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A lazy read pauses and resumes its measurement; a failing clock must not reach the caller's loop. */
#[CoversClass(SafeMeasurement::class)]
final class SafeMeasurementTest extends TestCase
{
    private RecordingLogger $logger;

    private InstrumentationFailureReporter $reporter;

    private BreakableClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->reporter = new InstrumentationFailureReporter($this->logger);
        $this->clock = new BreakableClock();
    }

    #[Test]
    public function aClockFailureWhilePausingIsReportedNotThrown(): void
    {
        $measurement = $this->measurement();
        $this->clock->broken = true;

        $measurement->pause();

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('Duration pause failed', $this->logger->messageAt(0));
    }

    #[Test]
    public function aClockFailureWhileResumingIsReportedNotThrown(): void
    {
        $measurement = $this->measurement();
        $measurement->pause();
        $this->clock->broken = true;

        $measurement->resume();

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('Duration resume failed', $this->logger->messageAt(0));
    }

    private function measurement(): SafeMeasurement
    {
        $runtime = new DurationRuntime(new OtelDurationRecorder(), $this->reporter, $this->clock);

        return SafeMeasurement::started(
            DurationTimer::started(new RecordingHistogram(), DurationUnit::Seconds, $runtime),
            $this->reporter,
            'cache.getItems',
        );
    }
}
