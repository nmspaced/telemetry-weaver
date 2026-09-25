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
 * Writes response propagation headers on the main request's `kernel.response`, while the
 * server span is still current. Sub-requests and excluded requests are skipped.
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
