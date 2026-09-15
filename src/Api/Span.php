<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

use OpenTelemetry\API\Trace\SpanContextInterface;

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

    public function context(): SpanContextInterface;

    public function isRecording(): bool;
}
