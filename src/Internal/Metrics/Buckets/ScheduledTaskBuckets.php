<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

/**
 * Boundaries for one run of a scheduled task.
 *
 * A scheduled task is whatever the application put behind a cron expression, so the
 * useful range is wide: a cheap "is there anything to do" poll that returns in
 * milliseconds, and a nightly aggregation that runs for minutes, are both normal and
 * both have to stay distinguishable. The top boundary is five minutes because that is
 * roughly where a task stops being late and starts overlapping the next trigger of a
 * typical schedule — the point at which the distribution has said everything it can
 * and what is needed is an alert, not a finer bucket.
 */
final readonly class ScheduledTaskBuckets implements OperationBuckets
{
    #[\Override]
    public function unit(): DurationUnit
    {
        return DurationUnit::Seconds;
    }

    /** @return non-empty-list<float|int> */
    #[\Override]
    public function boundaries(): array
    {
        return [
            0.01,
            0.05,
            0.1,
            0.5,
            1,
            2.5,
            5,
            10,
            30,
            60,
            120,
            300,
        ];
    }
}
