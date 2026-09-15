<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * @internal
 */
final readonly class SafeCounter implements CounterInterface
{
    public function __construct(
        private CounterInterface $delegate,
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
    public function add(float|int $amount, iterable $attributes = [], ContextInterface|false|null $context = null): void
    {
        try {
            $this->delegate->add($amount, $attributes, $context);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Metric recording failed', $this->name, $throwable);
        }
    }
}
