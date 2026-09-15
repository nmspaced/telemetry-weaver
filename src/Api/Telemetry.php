<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * @api
 */
interface Telemetry
{
    /**
     * @template T
     *
     * @param non-empty-string $name
     *
     * @param \Closure(Span): T $work
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     *
     * @return T
     *
     * @throws \Throwable the original callback exception, never a telemetry failure
     */
    public function trace(string $name, \Closure $work, array $attributes = []): mixed;

    /**
     * @param non-empty-string $name
     */
    public function operation(string $name): Operation;

    public function metrics(): Metrics;

    /**
     * A snapshot of the span current at the call; resolve it at the call site.
     *
     * The view does not own the span and does not keep it alive: once that span has
     * ended and been released, writes through a retained view do nothing and `context()`
     * still returns the ids it was taken with. Storing one in a shared service is still a
     * mistake — it will not follow the next request — but it is no longer a leak.
     */
    public function currentSpan(): Span;
}
