<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * One measurement of one interval.
 *
 * Not readonly: whether the interval is already closed is state, and it has
 * to survive past construction — without it a caller that closes twice would
 * record the same interval twice and skew the distribution.
 *
 * The clock is monotonic nanoseconds; the unit comes from the same
 * OperationBuckets that supplied the boundaries, so the two cannot diverge.
 * @internal
 */
final class DurationTimer
{
    private readonly int $startedAt;

    private bool $stopped = false;

    private ?ContextInterface $context;

    public function __construct(
        private readonly HistogramInterface $histogram,
        private readonly DurationUnit $unit,
        private readonly ClockInterface $clock,
    ) {
        $this->startedAt = $clock->now();
        $this->context = Context::getCurrent();
    }

    /**
     * Silently ignores a repeated call: telemetry has no right to break the caller.
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function stop(array $attributes = []): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        $context = $this->context;
        $this->context = null;

        $this->histogram->record(
            $this->unit->fromNanoseconds(\max(0, $this->clock->now() - $this->startedAt)),
            $attributes,
            $context,
        );
    }

    public function cancel(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->context = null;
    }
}
