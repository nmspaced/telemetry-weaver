<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * @internal The one place `OpenTelemetry\Context` is read on someone else's behalf.
 *
 * Through the injected storage rather than `Context::getCurrent()`, for the reason every
 * adapter here takes one: the static accessor reads whichever storage the process last
 * installed, and a test that swaps in a fiber-bound one — or a worker that rebuilt its
 * container — would be reading a different place than the spans were activated in.
 */
final readonly class OtelActiveTrace implements ActiveTrace
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
    ) {}

    /**
     * An invalid or half-formed context is reported as absence rather than as the all-zero
     * id the API returns: a caller asking for a trace wants something to look up, and
     * `0000…` is not that.
     */
    #[\Override]
    public function current(): ?TraceContext
    {
        try {
            $span = Span::fromContext($this->contextStorage->current())->getContext();

            if (!$span->isValid()) {
                return null;
            }

            return new TraceContext($span->getTraceId(), $span->getSpanId(), $span->getTraceFlags());
        } catch (\Throwable) {
            // Called by the log processor itself: reporting through the application
            // logger would recursively attempt the same failing context read.
            return null;
        }
    }
}
