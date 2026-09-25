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
     * Marks the span as failed without recording an exception.
     *
     * @param non-empty-string $type
     */
    public function fail(string $type): void;

    /**
     * The trace id as lowercase hex, or null when the operation has no valid span.
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
