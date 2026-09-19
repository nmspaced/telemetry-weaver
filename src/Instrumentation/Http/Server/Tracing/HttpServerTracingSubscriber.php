<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing;

use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\KnownHttpMethods;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\HttpTelemetryPriority;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class HttpServerTracingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestTraceRegistry $requestTraces,
        private ParentContext $parentContext,
        private RequestPolicy $requestPolicy = new RequestPolicy(),
        private KnownHttpMethods $knownMethods = new KnownHttpMethods(),
        private ServerSpanAttributes $serverSpanAttributes = new ServerSpanAttributes(),
    ) {}

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onRequest', HttpTelemetryPriority::TRACE_REQUEST],
                ['onRoute',   HttpTelemetryPriority::TRACE_ROUTE],
            ],
            KernelEvents::EXCEPTION => [['onException', 0]],
            KernelEvents::RESPONSE => [['onResponse', HttpTelemetryPriority::TRACE_TERMINATE]],
            KernelEvents::FINISH_REQUEST => [['onFinishRequest', HttpTelemetryPriority::TRACE_TERMINATE]],
            KernelEvents::TERMINATE => [['onTerminate', HttpTelemetryPriority::TRACE_TERMINATE]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($this->requestPolicy->isExcluded($request)) {
            return;
        }

        $isMain = $event->isMainRequest();
        $method = HttpMethod::from($request, $this->knownMethods);

        $this->requestTraces->open(
            $request,
            $method,
            attributes: $this->serverSpanAttributes->from($request, $method),
            kind: $isMain ? SpanKind::Server : SpanKind::Internal,
            // A sub-request is not a boundary: no incoming trace means it continues the
            // main request's span rather than starting beside it.
            parent: $isMain ? $this->parentContext->fromHeaders($request) : null,
        );
    }

    public function onRoute(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $this->requestTraces->of($request)?->route($this->requestPolicy->routeTemplate($request));
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->requestTraces->of($event->getRequest())?->exception($event->getThrowable());
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $trace = $this->requestTraces->of($request);

        if ($trace === null) {
            return;
        }

        $trace->route($this->requestPolicy->routeTemplate($request));
        $trace->response($event->getResponse());
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->isMainRequest()) {
            $this->requestTraces->of($request)?->detach();

            return;
        }

        $this->requestTraces->finish($request);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $this->requestTraces->of($request)?->response($event->getResponse());
        $this->requestTraces->finish($request);
    }
}
