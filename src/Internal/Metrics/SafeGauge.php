<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * @internal
 */
// @mago-expect analysis:experimental-usage — the synchronous Gauge is stable in the metrics
// specification; only its PHP interface still carries the marker, and refusing to expose it
// would leave applications reaching past the facade for it.
final readonly class SafeGauge implements GaugeInterface
{
    public function __construct(
        private GaugeInterface $delegate,
        private InstrumentationFailureReporter $reporter,
        private string $name,
    ) {}

    #[\Override]
    public function isEnabled(): bool
    {
        try {
            return $this->delegate->isEnabled();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Instrument state read failed', $this->name, $throwable);

            return false;
        }
    }

    /**
     * @param iterable<non-empty-string, string|bool|float|int|array<array-key, mixed>|null> $attributes
     */
    #[\Override]
    public function record(
        float|int $amount,
        iterable $attributes = [],
        ContextInterface|false|null $context = null,
    ): void {
        try {
            $this->delegate->record($amount, $attributes, $context);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Metric recording failed', $this->name, $throwable);
        }
    }
}
