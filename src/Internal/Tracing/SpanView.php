<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

/**
 * @internal Mutable only so an owner can revoke access and release its SDK span on completion.
 *
 * Two shapes. A *borrowed* view is handed out by an owner (`OwnedSpan`) that holds the
 * span strongly and calls `release()` when it ends. A *current* view comes from
 * `Telemetry::currentSpan()`, where there is no owner to revoke it: it holds the span
 * weakly plus an immutable `SpanContext` snapshot. A shared service that keeps such a view
 * across requests therefore keeps a few trace ids, never request A's SDK span — and once
 * that span is gone every write is a no-op, so nothing can land in request B.
 */
// @mago-expect lint:too-many-methods — implements the borrowed Span API plus internal ownership revocation and outcome tracking
final class SpanView implements Span
{
    /**
     * @var non-empty-string|null
     */
    private ?string $errorType = null;

    /**
     * @param \WeakReference<SpanInterface>|null $current
     */
    private function __construct(
        private ?SpanInterface $delegate,
        private readonly ?InstrumentationFailureReporter $reporter,
        private readonly ?\WeakReference $current = null,
        private readonly ?SpanContextInterface $snapshot = null,
    ) {}

    public static function borrowed(SpanInterface $span, ?InstrumentationFailureReporter $reporter): self
    {
        return new self($span, $reporter);
    }

    /**
     * A non-owning view of the span that is current right now.
     *
     * @throws \Throwable when the span cannot report its context; the caller contains it
     */
    public static function current(SpanInterface $span, ?InstrumentationFailureReporter $reporter): self
    {
        return new self(null, $reporter, \WeakReference::create($span), $span->getContext());
    }

    public function release(): void
    {
        $this->delegate = null;
        $this->errorType = null;
    }

    /**
     * @return non-empty-string|null
     */
    public function errorType(): ?string
    {
        return $this->errorType;
    }

    #[\Override]
    public function attribute(string $name, string|int|float|bool|array|null $value): void
    {
        $this->attributes([$name => $value]);
    }

    #[\Override]
    public function attributes(array $attributes): void
    {
        if ($this->target() === null) {
            return;
        }

        $this->rememberErrorType($attributes);
        $this->change(static fn(SpanInterface $span): SpanInterface => $span->setAttributes($attributes));
    }

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function rememberErrorType(array $attributes): void
    {
        if ($this->target() === null || !\array_key_exists(ErrorAttributes::ERROR_TYPE, $attributes)) {
            return;
        }

        $type = $attributes[ErrorAttributes::ERROR_TYPE];
        $this->errorType = \is_string($type) && $type !== '' ? $type : null;
    }

    #[\Override]
    public function rename(string $name): void
    {
        $this->change(static fn(SpanInterface $span): SpanInterface => $span->updateName($name));
    }

    #[\Override]
    public function event(string $name, array $attributes = []): void
    {
        $this->change(static fn(SpanInterface $span): SpanInterface => $span->addEvent($name, $attributes));
    }

    #[\Override]
    public function recordException(\Throwable $error): void
    {
        $this->change(static fn(SpanInterface $span): SpanInterface => $span->recordException($error));
    }

    #[\Override]
    public function fail(string $type): void
    {
        $this->attribute(ErrorAttributes::ERROR_TYPE, $type);
        $this->change(static fn(SpanInterface $span): SpanInterface => $span->setStatus(StatusCode::STATUS_ERROR));
    }

    #[\Override]
    public function context(): SpanContextInterface
    {
        try {
            return $this->target()?->getContext() ?? $this->snapshot ?? SpanContext::getInvalid();
        } catch (\Throwable $throwable) {
            $this->reporter?->report('Span context read failed', self::class, $throwable);

            return SpanContext::getInvalid();
        }
    }

    #[\Override]
    public function isRecording(): bool
    {
        try {
            return $this->target()?->isRecording() ?? false;
        } catch (\Throwable $throwable) {
            $this->reporter?->report('Span recording state read failed', self::class, $throwable);

            return false;
        }
    }

    /**
     * The span this view writes to: the owned one, or the current one while it is alive.
     */
    private function target(): ?SpanInterface
    {
        return $this->delegate ?? $this->current?->get();
    }

    /** @param \Closure(SpanInterface): mixed $change */
    private function change(\Closure $change): void
    {
        try {
            $span = $this->target();

            if ($span !== null && $span->isRecording()) {
                $change($span);
            }
        } catch (\Throwable $throwable) {
            $this->reporter?->report('Span enrichment failed', self::class, $throwable);
        }
    }
}
