<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\ControllerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Controller\TraceableArgumentResolver;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use Nmspaced\TelemetryWeaver\Tests\Fake\OrderController;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;

/**
 * The HTTP kernel of {@see HttpTelemetryTestCase} with the controller instrumentation installed.
 *
 * @internal
 */
abstract class ControllerTelemetryTestCase extends HttpTelemetryTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->instrument();
    }

    /** Installs the instrumentation again, after `boot()` has replaced the kernel. */
    protected function instrument(): void
    {
        $telemetry = new ControllerTelemetry(
            TelemetryFactory::tracing($this->spans, $this->reporter),
            new OtelActiveTrace($this->contextStorage),
        );

        $this->dispatcher->addSubscriber(new ControllerTelemetrySubscriber($telemetry));
        $this->kernel = new HttpKernel(
            $this->dispatcher,
            new ControllerResolver(),
            $this->requestStack,
            new TraceableArgumentResolver(new ArgumentResolver(), $telemetry),
            handleAllThrowables: true,
        );
    }

    /**
     * A request for `/orders/7` routed to one of {@see OrderController}'s methods.
     *
     * @param 'show'|'view'|'fail'|'missing' $method
     * @param \Closure(): void|null $probe
     *
     * @throws \Throwable
     */
    protected function orderRequest(string $method, ?\Closure $probe = null): Request
    {
        $request = Request::create('/orders/7');
        $request->attributes->set('_controller', [
            $probe === null ? new OrderController() : new OrderController($probe),
            $method,
        ]);
        $request->attributes->set('id', '7');

        return $request;
    }

    protected function span(string $name): ImmutableSpan
    {
        foreach ($this->exported() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        self::fail('No exported span named ' . $name);
    }
}
