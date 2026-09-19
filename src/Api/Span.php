<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * A borrowed view: enrichment never transfers ownership of the span or its activation.
 *
 * @api
 */
interface Span
{
    /**
     * @param non-empty-string $name
     * @param string|int|float|bool|list<string|int|float|bool>|null $value
     */
    public function attribute(string $name, string|int|float|bool|array|null $value): void;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function attributes(array $attributes): void;

    /**
     * @param non-empty-string $name
     */
    public function rename(string $name): void;

    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function event(string $name, array $attributes = []): void;

    public function recordException(\Throwable $error): void;

    /**
     * Mark an unsuccessful outcome without manufacturing an exception event.
     * @param non-empty-string $type
     */
    public function fail(string $type): void;

    /**
     * The ids this span is known by, as the lowercase hex the W3C trace context uses.
     *
     * Values, not a handle: there is nothing here to write through, and holding them past
     * the operation is safe because they name a trace that has already happened. They exist
     * for the two things an application does with a trace id — show it on an error page so a
     * report can be matched to a trace, and hand it to a system that correlates by id.
     *
     * Both are null together, whenever the operation has no valid span: tracing is off, the
     * component is off, or the span was suppressed.
     *
     * @return non-empty-string|null
     */
    public function traceId(): ?string;

    /**
     * @see self::traceId()
     *
     * @return non-empty-string|null
     */
    public function spanId(): ?string;

    public function isRecording(): bool;
}
