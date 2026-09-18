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

    /**
     * The context each recording carried, positionally aligned with `$records`.
     *
     * Kept apart from `$records` rather than as a third element so that the assertions
     * about the value and the attributes stay readable. It is captured at all because the
     * context is what a trace-based exemplar filter reads: a recording that arrives
     * without one is correlated with nothing, and dropping it here once hid that.
     *
     * @var list<ContextInterface|false|null>
     */
    public array $contexts = [];

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
        $this->contexts[] = $context;
    }
}
