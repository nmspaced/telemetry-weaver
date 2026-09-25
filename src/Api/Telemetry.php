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
     * @param \Closure(Span): T $work
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
}
