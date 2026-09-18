<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * An extracted OpenTelemetry context, carried as an opaque incoming trace.
 *
 * The context is always resolved against the root, so an unusable carrier yields a context
 * that is simply empty — which is exactly what "the boundary carried nothing" should mean
 * when it is used as a parent. No branch is needed anywhere else.
 *
 * @internal
 */
final readonly class OtelIncomingTrace implements IncomingTrace
{
    private function __construct(
        public ContextInterface $context,
    ) {}

    /**
     * A context a propagator resolved from a carrier. It may still be empty — that is what
     * `isValid()` answers — and an empty one is the honest result of a boundary that
     * carried nothing.
     */
    public static function extracted(ContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * A boundary that carried no trace at all.
     *
     * The root context, which as a parent means "start a new trace" rather than "continue
     * whatever this process is doing" — the distinction the whole incoming-trace type
     * exists to keep.
     */
    public static function none(): self
    {
        return new self(Context::getRoot());
    }

    #[\Override]
    public function isValid(): bool
    {
        return Span::fromContext($this->context)->getContext()->isValid();
    }
}
