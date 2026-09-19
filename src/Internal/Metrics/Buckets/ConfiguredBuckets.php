<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

/**
 * Resolves a component's boundaries: the ones the application configured, or the preset.
 *
 * A factory rather than a branch in each instrumentation class, because the choice is the
 * container's and the instrumentation should only ever see one `OperationBuckets`.
 *
 * @internal
 */
final readonly class ConfiguredBuckets
{
    /**
     * @param array<array-key, float|int> $boundaries empty keeps the preset
     *
     * @throws \InvalidArgumentException if the boundaries are unordered; the configuration tree
     *                                   rejects that first, so reaching this is a defect
     */
    public static function orDefault(DefaultBuckets $preset, array $boundaries): OperationBuckets
    {
        if ($boundaries === []) {
            return $preset;
        }

        return new CustomOperationBuckets($preset->unit(), $boundaries);
    }
}
