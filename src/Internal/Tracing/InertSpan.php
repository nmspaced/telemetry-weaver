<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\Span;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

// @mago-expect lint:too-many-methods — it implements two interfaces at once on purpose; see below
/**
 * The span of an operation that records none: tracing switched off, or a span suppressed.
 *
 * Owner and view in one object, because there is nothing to revoke — with no span behind it,
 * a view kept past the operation can no more reach the next request's data than it could
 * reach this one's. Separating them would be ceremony around a no-op.
 *
 * It holds no OpenTelemetry object at all. That matters twice over: an application that
 * switched the bundle off pays for no SDK type it will never use, and `Internal\Tracing` gets
 * to stay free of `OpenTelemetry\API\Trace` so the perimeter rule can be stated without an
 * exception.
 *
 * Two things it is not inert about. The correlation is kept, because a suppressed operation
 * still runs inside whatever trace its caller opened and its duration still belongs there.
 * And `error.type` is remembered, because the duration metric of a suppressed operation is
 * the only signal it produces, and an unlabelled failure in it is indistinguishable from a
 * success.
 *
 * @internal
 */
final class InertSpan implements Span, SpanOwner
{
    /** @var non-empty-string|null */
    private ?string $errorType = null;

    /**
     * @param non-empty-string $name
     */
    public function __construct(
        private readonly string $name,
        private readonly ?TraceCorrelation $correlation = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function view(): Span
    {
        return $this;
    }

    #[\Override]
    public function errorType(): ?string
    {
        return $this->errorType;
    }

    /**
     * An absent key leaves what was remembered alone — the same rule the recording view
     * follows, so a description built in two calls behaves identically either way.
     */
    #[\Override]
    public function rememberErrorType(array $attributes): void
    {
        if (!\array_key_exists(ErrorAttributes::ERROR_TYPE, $attributes)) {
            return;
        }

        $type = $attributes[ErrorAttributes::ERROR_TYPE];
        $this->errorType = \is_string($type) && $type !== '' ? $type : null;
    }

    #[\Override]
    public function correlation(): ?TraceCorrelation
    {
        return $this->correlation;
    }

    #[\Override]
    public function detach(): void
    {
        // Nothing was activated.
    }

    #[\Override]
    public function finish(): void
    {
        // Nothing was started.
    }

    #[\Override]
    public function attribute(string $name, string|int|float|bool|array|null $value): void
    {
        $this->attributes([$name => $value]);
    }

    #[\Override]
    public function attributes(array $attributes): void
    {
        $this->rememberErrorType($attributes);
    }

    #[\Override]
    public function rename(string $name): void
    {
        // No span to rename.
    }

    #[\Override]
    public function event(string $name, array $attributes = []): void
    {
        // No span to annotate.
    }

    #[\Override]
    public function recordException(\Throwable $error): void
    {
        // No span to record on; the duration metric carries the outcome instead.
    }

    #[\Override]
    public function fail(string $type): void
    {
        $this->errorType = $type;
    }

    #[\Override]
    public function traceId(): ?string
    {
        return null;
    }

    #[\Override]
    public function spanId(): ?string
    {
        return null;
    }

    #[\Override]
    public function isRecording(): bool
    {
        return false;
    }
}
