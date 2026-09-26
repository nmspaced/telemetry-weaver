<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\Span;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

// @mago-expect lint:too-many-methods — owner and view in one object
/**
 * The span of an operation that records none. Owner and view in one object, with no
 * OpenTelemetry types; it still keeps the correlation and `error.type` for the duration metric.
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

    /** An absent key keeps the remembered type, as the recording view does. */
    #[\Override]
    public function rememberErrorType(array $attributes): void
    {
        if (!\array_key_exists(ErrorAttributes::ERROR_TYPE, $attributes)) {
            return;
        }

        $this->errorType = ErrorType::from($attributes[ErrorAttributes::ERROR_TYPE]);
    }

    #[\Override]
    public function correlation(): ?TraceCorrelation
    {
        return $this->correlation;
    }

    #[\Override]
    public function attach(): void {}

    #[\Override]
    public function detach(): void {}

    #[\Override]
    public function finish(): void {}

    #[\Override]
    public function isAbandoned(): bool
    {
        return false;
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
    public function rename(string $name): void {}

    #[\Override]
    public function event(string $name, array $attributes = []): void {}

    #[\Override]
    public function recordException(\Throwable $error): void {}

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
