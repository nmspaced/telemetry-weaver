<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\ContextInterface;

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

    public function parent(ContextInterface $context): self;

    public function root(): self;

    /**
     * Relate the span to another one it does not descend from — the ambient span a
     * message was processed inside, when the message's own creation context is the
     * parent. An invalid context is ignored.
     */
    public function link(SpanContextInterface $context): self;

    /**
     * Suppress this operation's span while preserving metrics and the ambient parent.
     */
    public function withoutSpan(): self;

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
