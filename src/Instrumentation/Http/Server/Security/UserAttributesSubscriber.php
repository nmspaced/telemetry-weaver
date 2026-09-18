<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\HttpTelemetryPriority;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts the authenticated user on the server span, once the firewall has decided who it is.
 *
 * On `kernel.controller` rather than `kernel.request`: the firewall authenticates during
 * `kernel.request`, and at the priority the server span is opened at (2048) there is no token
 * yet. By the time a controller has been resolved there is one, and the span this writes to
 * is still open — it is not closed until `kernel.response`.
 *
 * Only the main request. A sub-request is handled inside the main one's span, so writing the
 * same attributes again would be at best a no-op and at worst a different user's identity on
 * a span that already names one.
 *
 * @internal
 */
final readonly class UserAttributesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RequestTraceRegistry $requestTraces,
        private UserAttributes $users,
        private InstrumentationFailureReporter $reporter,
    ) {}

    /**
     * @return array<string, list<array{string, int}>>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => [['onController', HttpTelemetryPriority::TRACE_ROUTE]]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $trace = $this->requestTraces->of($event->getRequest());
        if ($trace === null) {
            return;
        }

        try {
            $attributes = $this->users->current();
        } catch (\Throwable $throwable) {
            // A token storage that throws is the application's problem, not the request's:
            // reading who is logged in must never be the reason a response is not produced.
            $this->reporter->report('User attribute resolution failed', self::class, $throwable);

            return;
        }

        if ($attributes === []) {
            return;
        }

        $trace->attributes($attributes);
    }
}
