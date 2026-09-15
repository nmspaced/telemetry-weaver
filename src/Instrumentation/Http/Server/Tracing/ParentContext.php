<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parent context for a request scope: headers for the main request, process context for sub-requests.
 */
final readonly class ParentContext
{
    public function __construct(
        private TextMapPropagatorInterface $propagator,
        private ContextStorageInterface $contextStorage,
        private PropagationGetterInterface $headersGetter = new RequestHeadersGetter(),
    ) {}

    /**
     * Main request: headers only.
     *
     * The extraction base is explicit — the root context. Without it,
     * propagator->extract() defaults to Context::getCurrent(), so a request
     * without a valid traceparent would inherit whatever is on top of the
     * process stack (e.g. a leaked scope from a previous request).
     *
     * The carrier is the HeaderBag itself; see RequestHeadersGetter.
     */
    public function fromHeaders(Request $request): ContextInterface
    {
        return $this->propagator->extract($request->headers, $this->headersGetter, Context::getRoot());
    }

    /**
     * Sub-request: current process context — headers are inherited from the main request and would create a second root.
     */
    public function fromProcess(): ContextInterface
    {
        return $this->contextStorage->current();
    }
}
