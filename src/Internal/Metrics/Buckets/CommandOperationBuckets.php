<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

/**
 * Boundaries for one console command run.
 *
 * The widest set in the package, because a console command is the least constrained
 * thing the bundle measures: `cache:pool:prune` finishes in milliseconds and an import
 * command runs for a quarter of an hour, and both are the same instrument. Ten minutes
 * is the last boundary rather than an arbitrary larger one because a command that
 * exceeds it is a worker in everything but name and belongs in `excluded_commands` —
 * its span would stay open for the life of the process, which is exactly what the
 * exclusion list exists to prevent.
 */
final readonly class CommandOperationBuckets implements OperationBuckets
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
            0.05,
            0.1,
            0.5,
            1,
            2.5,
            5,
            10,
            30,
            60,
            300,
            600,
        ];
    }
}
