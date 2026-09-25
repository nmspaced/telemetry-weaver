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
 * @internal
 */
final readonly class OtelIncomingTrace implements IncomingTrace
{
    private function __construct(
        public ContextInterface $context,
    ) {}

    /**
     * A context a propagator extracted; it may be empty when the carrier had no trace.
     */
    public static function extracted(ContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * A boundary that carried no trace: the root context, so a new trace starts.
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
