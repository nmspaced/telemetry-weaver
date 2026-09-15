<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

final readonly class DatabaseOperationBuckets implements OperationBuckets
{
    #[\Override]
    public function unit(): DurationUnit
    {
        return DurationUnit::Seconds;
    }

    /**
     * @return non-empty-list<float|int>
     */
    #[\Override]
    public function boundaries(): array
    {
        return [
            0.001,
            0.005,
            0.01,
            0.05,
            0.1,
            0.5,
            1,
            5,
            10,
        ];
    }
}
