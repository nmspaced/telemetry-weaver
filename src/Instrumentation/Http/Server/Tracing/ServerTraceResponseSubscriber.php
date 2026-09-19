<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\HttpTelemetryPriority;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Internal\Propagation\ResponsePropagation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the trace back to the caller.
 *
 * Its own subscriber rather than another branch in {@see HttpServerTracingSubscriber},
 * because it shares nothing with span lifecycle: it never touches the trace registry, holds
 * no per-request state, and has nothing to release. What it needs is a moment when the
 * server span is still current and a response that is actually going to be sent — the same
 * split the metrics subscriber already has from the tracing one.
 *
 * On `kernel.response`, after the tracing subscriber has recorded the outcome and before
 * `kernel.finish_request` releases the span's activation. A sub-request is skipped: its
 * response is rendered into the page rather than sent, so headers on it reach nobody, and
 * asking per sub-request would overwrite the main request's header with an internal span.
 *
 * An excluded request is skipped for the same reason it has no span. Nothing would be
 * written anyway — a propagator with no valid span in context produces nothing — but a
 * request the application asked to keep out of tracing should not be answered with a trace
 * header just because something else left a span active.
 */
final readonly class ServerTraceResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ResponsePropagation $responsePropagation,
        private RequestPolicy $requestPolicy = new RequestPolicy(),
    ) {}

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => [['onResponse', HttpTelemetryPriority::TRACE_RESPONSE]]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $this->requestPolicy->isExcluded($request)) {
            return;
        }

        $headers = $event->getResponse()->headers;

        foreach ($this->responsePropagation->headers() as $name => $value) {
            $headers->set($name, $value);
        }
    }
}
