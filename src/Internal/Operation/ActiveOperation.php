<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Measurement;
use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOwner;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

// @mago-expect lint:too-many-methods — the running-operation contract plus the two things a
// framework lifecycle needs from an operation it owns: releasing the activation early, and
// naming the trace a later measurement of the same work belongs to.
/**
 * @internal Mutable execution ownership, never stored by a shared facade. First completion revokes all retained work.
 */
final class ActiveOperation implements ScopedOperation
{
    private bool $finished = false;

    /**
     * The type given to `fail()`, kept apart from the attribute arrays: in metric-only
     * mode the span is inert and remembers nothing, and a later `metricAttributes()` or
     * span attribute must not silently replace what the caller declared.
     *
     * @var non-empty-string|null
     */
    private ?string $failure = null;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    private function __construct(
        private readonly SpanOwner $owner,
        private ?Measurement $measurement,
        private array $attributes,
        private readonly InstrumentationFailureReporter $reporter,
        private readonly BaggageReader $baggage,
    ) {}

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public static function owning(
        SpanOwner $span,
        Measurement $measurement,
        array $attributes,
        InstrumentationFailureReporter $reporter,
        BaggageReader $baggage,
    ): self {
        return new self($span, $measurement, $attributes, $reporter, $baggage);
    }

    #[\Override]
    public function span(): Span
    {
        return $this->owner->view();
    }

    #[\Override]
    public function baggage(): array
    {
        try {
            return $this->baggage->of($this->owner->correlation());
        } catch (\Throwable $throwable) {
            $this->reporter->report('Baggage read failed', $this->owner->name(), $throwable);

            return [];
        }
    }

    #[\Override]
    public function metricAttributes(array $attributes): void
    {
        if ($this->finished) {
            return;
        }

        $this->attributes = \array_replace($this->attributes, $attributes);
    }

    #[\Override]
    public function fail(string $type): void
    {
        if ($this->finished) {
            return;
        }

        $this->failure = $type;
        $this->attributes[ErrorAttributes::ERROR_TYPE] = $type;
        $this->owner->view()->fail($type);
    }

    #[\Override]
    public function detach(): void
    {
        $this->owner->detach();
    }

    #[\Override]
    public function suspend(): void
    {
        $this->owner->detach();
        $this->measurement?->pause();
    }

    #[\Override]
    public function resume(): void
    {
        // Safe after `finish()`: the owner releases its re-entry and the measurement is gone.
        $this->owner->attach();
        $this->measurement?->resume();
    }

    #[\Override]
    public function correlation(): ?TraceCorrelation
    {
        if ($this->finished) {
            return null;
        }

        try {
            return $this->owner->correlation();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Operation correlation read failed', $this->owner->name(), $throwable);

            return null;
        }
    }

    #[\Override]
    public function finish(?\Throwable $error = null): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $measurement = $this->measurement;
        $this->measurement = null;

        $attributes = $this->attributes;
        $this->attributes = [];

        $failure = $this->failure;
        $this->failure = null;
        try {
            if ($error !== null) {
                $span = $this->owner->view();
                $metricType = $attributes[ErrorAttributes::ERROR_TYPE] ?? null;
                $type =
                    $failure
                    ?? $this->owner->errorType()
                    ?? (\is_string($metricType) && $metricType !== '' ? $metricType : $error::class);
                $span->recordException($error);
                $span->fail($type);
                $attributes[ErrorAttributes::ERROR_TYPE] = $type;
            }

            $measurement?->stop($attributes);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Operation completion failed', $this->owner->name(), $throwable);
        } finally {
            $this->discard($measurement);
            $this->owner->finish();
        }
    }

    #[\Override]
    public function abandon(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $measurement = $this->measurement;
        $this->measurement = null;

        $this->attributes = [];
        $this->failure = null;
        $this->discard($measurement);
        $this->owner->finish();
    }

    private function discard(?Measurement $measurement): void
    {
        try {
            $measurement?->cancel();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Operation measurement cancellation failed', $this->owner->name(), $throwable);
        }
    }
}
