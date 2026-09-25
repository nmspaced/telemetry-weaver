<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\ErrorType;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

/**
 * @internal
 *
 * A borrowed view of a span. Once the owner releases it, writes are ignored and the ids
 * are gone, so a view kept past its request cannot reach another span.
 */
// @mago-expect lint:too-many-methods — Span API plus revocation and outcome tracking
final class SpanView implements Span
{
    /**
     * @var non-empty-string|null
     */
    private ?string $errorType = null;

    private function __construct(
        private ?SpanInterface $delegate,
        private readonly ?InstrumentationFailureReporter $reporter,
    ) {}

    public static function borrowed(SpanInterface $span, ?InstrumentationFailureReporter $reporter): self
    {
        return new self($span, $reporter);
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
        if ($this->delegate === null) {
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
        if ($this->delegate === null || !\array_key_exists(ErrorAttributes::ERROR_TYPE, $attributes)) {
            return;
        }

        $this->errorType = ErrorType::from($attributes[ErrorAttributes::ERROR_TYPE]);
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
    public function traceId(): ?string
    {
        return $this->identifier(static fn(SpanInterface $span): string => $span->getContext()->getTraceId());
    }

    #[\Override]
    public function spanId(): ?string
    {
        return $this->identifier(static fn(SpanInterface $span): string => $span->getContext()->getSpanId());
    }

    #[\Override]
    public function isRecording(): bool
    {
        try {
            return $this->delegate?->isRecording() ?? false;
        } catch (\Throwable $throwable) {
            $this->reporter?->report('Span recording state read failed', self::class, $throwable);

            return false;
        }
    }

    /**
     * Reports an invalid id as null rather than the all-zero value.
     *
     * @param \Closure(SpanInterface): string $read
     *
     * @return non-empty-string|null
     */
    private function identifier(\Closure $read): ?string
    {
        try {
            $span = $this->delegate;

            if ($span === null || !$span->getContext()->isValid()) {
                return null;
            }

            $id = $read($span);

            if ($id === '') {
                return null;
            }

            return $id;
        } catch (\Throwable $throwable) {
            $this->reporter?->report('Span identity read failed', self::class, $throwable);

            return null;
        }
    }

    /** @param \Closure(SpanInterface): mixed $change */
    private function change(\Closure $change): void
    {
        try {
            $span = $this->delegate;

            if ($span !== null && $span->isRecording()) {
                $change($span);
            }
        } catch (\Throwable $throwable) {
            $this->reporter?->report('Span enrichment failed', self::class, $throwable);
        }
    }
}
