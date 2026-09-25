<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * Immutable description. Nothing starts until run() or start(); each fluent call returns a new description.
 *
 * @api
 */
interface Operation
{
    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function attributes(array $attributes): self;

    public function kind(SpanKind $kind): self;

    /**
     * Values sent with the trace to every service this operation calls.
     *
     * Baggage leaves the process: never put secrets or personal data in it. A repeated key
     * overrides the caller's value for this operation only.
     *
     * @param array<non-empty-string, string> $entries
     */
    public function baggage(array $entries): self;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function duration(Duration $duration, array $attributes = []): self;

    /**
     * @template T
     *
     * @param \Closure(OperationContext): T $work
     *
     * @return T
     *
     * @throws \Throwable the original callback exception
     */
    public function run(\Closure $work): mixed;

    /**
     * Starts the operation; the caller must finish or abandon it in the same execution.
     */
    public function start(): RunningOperation;
}
