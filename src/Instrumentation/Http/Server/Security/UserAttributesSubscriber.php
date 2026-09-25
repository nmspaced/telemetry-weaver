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
 * Adds the authenticated user to the main request's server span on `kernel.controller`, after
 * the firewall has run. The token is read only when a span exists.
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
            $this->reporter->report('User attribute resolution failed', self::class, $throwable);

            return;
        }

        if ($attributes === []) {
            return;
        }

        $trace->attributes($attributes);
    }
}
