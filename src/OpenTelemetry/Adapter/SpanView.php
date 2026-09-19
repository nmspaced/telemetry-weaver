<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

/**
 * @internal Mutable only so an owner can revoke access and release its SDK span on completion.
 *
 * Every view is borrowed: it is handed out by an owner (`OwnedSpan`) that holds the span
 * strongly and calls `release()` when it ends. After that, writes through a view someone
 * kept are no-ops and the ids are gone — a view stored in a shared service cannot reach
 * the next request's span, because it can no longer reach any span at all.
 */
// @mago-expect lint:too-many-methods — implements the borrowed Span API plus internal ownership revocation and outcome tracking
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
     * Invalid ids are reported as absence rather than as the all-zero id the API returns:
     * a caller asking for a trace id wants something to look up, and `0000…` is not that.
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

            return $id === '' ? null : $id;
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
