<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

/**
 * @internal
 */
interface OperationBuckets
{
    public function unit(): DurationUnit;

    /**
     * @return non-empty-list<float|int> strictly increasing values
     */
    public function boundaries(): array;
}
