<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * Captures what a DurationTimer recorded. Boundaries and unit reaching the exporter
 * are covered against a real MeterProvider in MeterTest — here the subject is
 * the elapsed value itself.
 */
final class RecordingHistogram implements HistogramInterface
{
    /** @var list<array{float|int, iterable<string, mixed>}> */
    public array $records = [];

    #[\Override]
    public function isEnabled(): bool
    {
        return true;
    }

    #[\Override]
    public function record(
        float|int $amount,
        iterable $attributes = [],
        ContextInterface|false|null $context = null,
    ): void {
        $this->records[] = [$amount, $attributes];
    }
}
