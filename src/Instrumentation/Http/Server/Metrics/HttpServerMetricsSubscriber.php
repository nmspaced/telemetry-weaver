<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

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

final readonly class HttpServerMetricsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestMeasurementRegistry $measurements,
        private RequestPolicy $requestPolicy = new RequestPolicy(),
        private KnownHttpMethods $knownMethods = new KnownHttpMethods(),
    ) {}

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onRequest', HttpTelemetryPriority::METRIC_REQUEST],
                ['onRoute',   HttpTelemetryPriority::METRIC_ROUTE],
            ],
            KernelEvents::EXCEPTION => [['onException', 0]],
            KernelEvents::RESPONSE => [['onResponse', HttpTelemetryPriority::METRIC_TERMINATE]],
            KernelEvents::FINISH_REQUEST => [['onFinishRequest', HttpTelemetryPriority::METRIC_TERMINATE]],
            KernelEvents::TERMINATE => [['onTerminate', HttpTelemetryPriority::METRIC_TERMINATE]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->requestPolicy->isExcluded($request)) {
            $this->measurements->open($request, $this->knownMethods);
        }
    }

    public function onRoute(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->measurements->route($event->getRequest(), $this->requestPolicy);
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $measurement = $this->measurements->of($event->getRequest());
        if ($measurement !== null) {
            $measurement->exception($event->getThrowable());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $measurement = $this->measurements->of($event->getRequest());
        if ($measurement !== null) {
            $measurement->response($event->getResponse());
            $this->measurements->route($event->getRequest(), $this->requestPolicy);
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $measurement = $this->measurements->of($event->getRequest());
        if ($measurement !== null && $measurement->isUnfinishedAfterException()) {
            $this->measurements->route($event->getRequest(), $this->requestPolicy);
            $this->measurements->finish($event->getRequest());
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $measurement = $this->measurements->of($request);
        if ($measurement === null) {
            return;
        }

        $measurement->response($event->getResponse());
        $this->measurements->route($request, $this->requestPolicy);
        $this->measurements->finish($request);
    }
}
