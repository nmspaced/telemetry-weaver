<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;

final readonly class CustomOperationBuckets implements OperationBuckets
{
    /**
     * @var non-empty-list<float|int>
     */
    private array $boundaries;

    /**
     * @param array<array-key, float|int> $boundaries strictly increasing
     *
     * @throws \InvalidArgumentException if boundaries are empty or unordered
     */
    public function __construct(
        private DurationUnit $unit,
        array $boundaries,
    ) {
        if ($boundaries === []) {
            throw new \InvalidArgumentException('Histogram boundaries must not be empty.');
        }

        $previous = null;

        foreach ($boundaries as $boundary) {
            if ($previous !== null && $boundary <= $previous) {
                throw new \InvalidArgumentException(\sprintf(
                    'Histogram boundaries must be strictly increasing, got %s after %s.',
                    (string) $boundary,
                    (string) $previous,
                ));
            }

            $previous = $boundary;
        }

        $this->boundaries = \array_values($boundaries);
    }

    #[\Override]
    public function unit(): DurationUnit
    {
        return $this->unit;
    }

    /**
     * @return non-empty-list<float|int>
     */
    #[\Override]
    public function boundaries(): array
    {
        return $this->boundaries;
    }
}
