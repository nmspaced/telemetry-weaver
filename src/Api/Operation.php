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
     * Values carried with the trace, into this operation and every service it calls.
     *
     * Baggage is not an attribute. An attribute describes the span it is set on and stops
     * there; baggage is added to the outgoing headers of every request made inside the
     * operation, so it leaves this process and reaches services that are not yours. Put a
     * tenant or a feature-flag cohort in it — something the whole call graph needs to
     * agree on — and never a token, a personal identifier, or anything whose disclosure
     * you would have to report. There is no way to unsend it.
     *
     * Entries add to whatever the caller already propagated; a repeated key replaces it
     * for this operation and everything it calls, not for the caller.
     *
     * An operation whose span is suppressed carries no baggage: the entries live in the
     * context activation the span owns, and there is none.
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
     * The caller must finish or abandon this operation in the execution context that started it.
     */
    public function start(): RunningOperation;
}
