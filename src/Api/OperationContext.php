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
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function metricAttributes(array $attributes): void;

    /**
     * Mark the operation as failed, on the span and on its duration at once, without an
     * exception.
     *
     * Sets the span status to error and `error.type` on both signals, whichever of them
     * is enabled. The last call wins; an exception that escapes later is still recorded,
     * but does not replace the type given here. After the operation has finished or been
     * abandoned this does nothing. `span()->fail()` remains the span-only variant.
     *
     * @param non-empty-string $type a low-cardinality reason, e.g. `payment.declined`
     */
    public function fail(string $type): void;
}
