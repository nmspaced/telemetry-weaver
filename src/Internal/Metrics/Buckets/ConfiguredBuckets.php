<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

/**
 * A component's configured boundaries, or its preset.
 *
 * @internal
 */
final readonly class ConfiguredBuckets
{
    /**
     * @param array<array-key, float|int> $boundaries empty keeps the preset
     *
     * @throws \InvalidArgumentException if the boundaries are unordered
     */
    public static function orDefault(DefaultBuckets $preset, array $boundaries): OperationBuckets
    {
        if ($boundaries === []) {
            return $preset;
        }

        return new CustomOperationBuckets($preset->unit(), $boundaries);
    }
}
