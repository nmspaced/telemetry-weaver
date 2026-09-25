<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\Span;

/**
 * A span the package created, with its activation, seen without OpenTelemetry types. Only
 * the owner ends or detaches it; views it hands out are revoked when it finishes.
 *
 * @internal
 */
interface SpanOwner
{
    /** @return non-empty-string the operation's name, for diagnostics */
    public function name(): string;

    /** A borrowed view for enrichment; it stops working once the owner finishes. */
    public function view(): Span;

    /**
     * The `error.type` the operation was described with, if any.
     *
     * @return non-empty-string|null
     */
    public function errorType(): ?string;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function rememberErrorType(array $attributes): void;

    /** The trace a measurement for this span belongs to; survives `detach()`. */
    public function correlation(): ?TraceCorrelation;

    /** Releases the activation, leaving the span open. */
    public function detach(): void;

    /**
     * Makes the span ambient again after `detach()`, for work resumed in pieces. Does nothing
     * if it is already active, was never activated, or has finished.
     */
    public function attach(): void;

    /** Detaches and ends. Idempotent. */
    public function finish(): void;
}
