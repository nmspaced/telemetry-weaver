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

/**
 * What one operation costs in memory while it is running.
 *
 * The package's first claim is that a worker serves thousands of requests without growing,
 * and the busiest path through it is a database query: Doctrine describes an operation —
 * name, kind, attributes, duration — on every statement, and each fluent call returns a new
 * immutable plan. That design is deliberate and is not in question here; what is in question
 * is the number, which nobody had measured.
 *
 * Measured from inside the callback, which is the only moment everything the description
 * built is simultaneously alive: the plan chain, the span, its activation, the measurement.
 * The smallest of several samples is used, because the allocator's own bookkeeping only ever
 * adds to a run, never subtracts, so the floor is the honest figure.
 *
 * The figures, on PHP 8.5 when this was written: **~2.4 KiB** for the description machinery
 * alone and **~14.9 KiB** with an SDK span open behind it. Both are per operation *in
 * flight*, and PHP runs one at a time, so a worker's steady state is bounded by nesting depth
 * rather than by how many requests it has served — which `repeatingAnOperationDoesNotGrow()`
 * is the other half of. The conclusion recorded here is that the immutable-plan design costs
 * kilobytes per concurrent operation, not per served request, and needs no change.
 *
 * The budgets are ceilings with room in them, not targets. They exist to catch the change
 * that adds a kilobyte per query, not to pin the current value — a test that fails on an
 * allocator detail would be deleted within a month, which is worse than no test.
 */
final class OperationFootprintTest extends TestCase
{
    /**
     * Weaver's own machinery with both signals off: the plan chain and the operation, with no
     * span and no measurement behind them. This is the floor a suppressed Doctrine query pays
     * — `only_with_parent` outside a trace takes exactly this path.
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

    /**
     * The claim the package is built on: a worker that runs the same operation five thousand
     * times ends where it started. Nothing is read back from the recorder here on purpose —
     * reading is what would retain the spans.
     *
     * @throws \Throwable
     */
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
        // The first call resolves instruments and warms the SDK's own caches; measuring it
        // would attribute one process's setup to every query.
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
