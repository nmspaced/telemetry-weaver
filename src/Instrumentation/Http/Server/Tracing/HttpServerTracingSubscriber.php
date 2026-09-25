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

        $method = HttpMethod::from($request, $this->knownMethods);
        $attributes = $this->serverSpanAttributes->from($request, $method);

        if (!$event->isMainRequest()) {
            $this->requestTraces->open($request, $method, attributes: $attributes, kind: SpanKind::Internal);

            return;
        }

        $this->requestTraces->open(
            $request,
            $method,
            attributes: $attributes,
            kind: SpanKind::Server,
            parent: $this->parentContext->fromHeaders($request),
        );
    }

    public function onRoute(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $this->requestTraces->route($request, $this->requestPolicy);
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->requestTraces->of($event->getRequest())?->exception($event->getThrowable());
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $this->requestTraces->route($request, $this->requestPolicy);
        $this->requestTraces->of($request)?->response($event->getResponse());
    }

    /**
     * A main request stays open for terminate, unless an exception escapes the kernel: then no
     * terminate follows, and without a reset the span would never be exported.
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();
        $trace = $this->requestTraces->of($request);

        if ($trace === null) {
            return;
        }

        if ($event->isMainRequest() && !$trace->isUnansweredAfterException()) {
            $trace->detach();

            return;
        }

        $this->requestTraces->route($request, $this->requestPolicy);
        $this->requestTraces->finish($request);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $this->requestTraces->of($request)?->response($event->getResponse());
        $this->requestTraces->finish($request);
    }
}
