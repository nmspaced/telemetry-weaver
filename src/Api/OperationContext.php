<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * @api
 */
interface OperationContext
{
    public function span(): Span;

    /**
     * Baggage propagated by the caller plus entries added by this operation. The values come
     * from other services: treat them as input.
     *
     * @return array<non-empty-string, string>
     */
    public function baggage(): array;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function metricAttributes(array $attributes): void;

    /**
     * Marks the operation as failed on both the span and the duration, without an exception.
     * The last call wins; it does nothing after the operation has finished.
     *
     * @param non-empty-string $type a low-cardinality reason, e.g. `payment.declined`
     */
    public function fail(string $type): void;
}
