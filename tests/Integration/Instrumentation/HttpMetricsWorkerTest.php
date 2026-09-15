<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Tests\Support\HttpMetricsTestCase;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpMetricsWorkerTest extends HttpMetricsTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function resetReleasesRequestAndMeasurementWithoutRecording(): void
    {
        $request = Request::create('/orders/1');
        $this->subscriber->onRequest(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $measurement = $this->measurements->of($request);
        self::assertNotNull($measurement);
        $requestReference = \WeakReference::create($request);
        $measurementReference = \WeakReference::create($measurement);
        unset($request, $measurement);

        $this->clock->advanceSeconds(60);
        $this->measurements->reset();
        $this->measurements->reset();
        self::assertNull($requestReference->get());
        self::assertNull($measurementReference->get());
        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        self::assertCount(0, $metric->data->dataPoints);
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function requestEventsDoNotPerformWorkerReset(): void
    {
        $request = Request::create('/orders/1');
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onRequest($event);
        $measurement = $this->measurements->of($request);
        self::assertNotNull($measurement);
        $this->subscriber->onRequest($event);
        $this->subscriber->onRequest(
            new RequestEvent($this->kernel, Request::create('/health'), HttpKernelInterface::MAIN_REQUEST),
        );
        self::assertSame($measurement, $this->measurements->of($request));
        $this->measurements->reset();
        self::assertNull($this->measurements->of($request));
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function sequentialRequestsDoNotRetainRequestObjectsOrLoseInstruments(): void
    {
        for ($index = 0; $index < 3; ++$index) {
            $request = $this->request(static fn(): Response => new Response());
            $reference = \WeakReference::create($request);
            $this->handle($request);
            unset($request);
            $this->measurements->reset();
            self::assertNull($reference->get());
        }

        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        self::assertCount(1, $metric->data->dataPoints);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame(3, $point->count);
        }

        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function lateCompletionCannotCloseTheNextRequest(): void
    {
        $previous = Request::create('/orders/1');
        $this->subscriber->onRequest(new RequestEvent($this->kernel, $previous, HttpKernelInterface::MAIN_REQUEST));
        $this->measurements->reset();
        $current = Request::create('/orders/2');
        $this->subscriber->onRequest(new RequestEvent($this->kernel, $current, HttpKernelInterface::MAIN_REQUEST));
        $measurement = $this->measurements->of($current);
        self::assertNotNull($measurement);
        $this->subscriber->onTerminate(new TerminateEvent($this->kernel, $previous, new Response()));
        self::assertSame($measurement, $this->measurements->of($current));
        $this->subscriber->onTerminate(new TerminateEvent($this->kernel, $current, new Response()));
        self::assertNull($this->measurements->of($current));
        $this->assertNoReports();
    }
}
