<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Functional;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;
use OpenTelemetry\SemConv\Attributes\DbAttributes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** What one operation costs in memory while it is running. */
final class OperationFootprintTest extends TestCase
{
    /**
     * Weaver's own machinery with both signals off: the plan chain and the operation, with no span
     * and no measurement behind them.
     */
    private const int SUPPRESSED_BUDGET_BYTES = 4_096;

    /** A recorded query: the above, plus the SDK span, its context activation and the timer. */
    private const int RECORDED_BUDGET_BYTES = 24_576;

    /** Enough iterations that a per-operation leak of a few bytes is visible above the noise. */
    private const int REPETITIONS = 5_000;

    /** @throws \Throwable */
    #[Test]
    public function aSuppressedOperationHoldsAlmostNothing(): void
    {
        $telemetry = DefaultTelemetry::disabled();

        self::assertLessThan(self::SUPPRESSED_BUDGET_BYTES, $this->liveBytes($telemetry, $this->duration($telemetry)));
    }

    /** @throws \Throwable */
    #[Test]
    public function aRecordedOperationHoldsABoundedAmount(): void
    {
        $telemetry = InMemoryTelemetry::create();

        try {
            self::assertLessThan(self::RECORDED_BUDGET_BYTES, $this->liveBytes(
                $telemetry,
                $this->duration($telemetry),
            ));
        } finally {
            $telemetry->shutdown();
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function repeatingAnOperationDoesNotGrow(): void
    {
        $telemetry = DefaultTelemetry::disabled();
        $duration = $this->duration($telemetry);

        $this->query($telemetry, $duration);
        \gc_collect_cycles();
        $before = \memory_get_usage();

        for ($i = 0; $i < self::REPETITIONS; ++$i) {
            $this->query($telemetry, $duration);
        }

        \gc_collect_cycles();

        self::assertLessThan(
            self::SUPPRESSED_BUDGET_BYTES,
            \memory_get_usage() - $before,
            'a finished operation retains nothing',
        );
    }

    private function duration(Telemetry $telemetry): Duration
    {
        return $telemetry
            ->metrics()
            ->duration('db.client.operation.duration', DurationUnit::Seconds, DefaultBuckets::Database->boundaries());
    }

    /**
     * The bytes alive at the moment the work runs, as the smallest of several samples.
     *
     * @throws \Throwable
     */
    private function liveBytes(Telemetry $telemetry, Duration $duration): int
    {
        $this->query($telemetry, $duration);

        $samples = [];
        for ($i = 0; $i < 5; ++$i) {
            \gc_collect_cycles();
            $before = \memory_get_usage();
            $samples[] = $this->query($telemetry, $duration, $before);
        }

        return \min($samples);
    }

    /**
     * One statement, described the way `DoctrineTelemetry` describes it.
     *
     * @return int bytes live inside the callback, relative to $before
     *
     * @throws \Throwable
     */
    private function query(Telemetry $telemetry, Duration $duration, int $before = 0): int
    {
        return $telemetry
            ->operation('SELECT orders')
            ->kind(SpanKind::Client)
            ->attributes([
                DbAttributes::DB_SYSTEM_NAME => 'postgresql',
                DbAttributes::DB_OPERATION_NAME => 'SELECT',
                DbAttributes::DB_COLLECTION_NAME => 'orders',
            ])
            ->duration($duration, [DbAttributes::DB_SYSTEM_NAME => 'postgresql'])
            ->run(static fn(): int => \memory_get_usage() - $before);
    }
}
