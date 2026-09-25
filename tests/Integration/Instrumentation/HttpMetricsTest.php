<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Tests\Support\HttpMetricsTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class HttpMetricsTest extends HttpMetricsTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function terminateResponseWinsOverAnEarlierServerError(): void
    {
        $request = $this->request(static fn(): Response => new Response('', 500));
        $this->handle($request, false);
        $this->kernel->terminate($request, new Response('', 202));
        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame(202, $point->attributes->get('http.response.status_code'));
            self::assertNull($point->attributes->get('error.type'));
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function recordsSecondsThroughTerminateWithBoundedLabelsAndBalancedActiveRequests(): void
    {
        $request = $this->request(
            /** @throws \Throwable */ function (): Response {
                $this->clock->advanceSeconds(0.1);
                $this->subRequest(static fn(): Response => new Response('fragment'));

                return new Response('ok');
            },
            '/orders/123?token=secret',
            ['_route' => 'orders'],
        );
        $request->server->set('SERVER_PROTOCOL', 'HTTP/2.0');

        $response = $this->handle($request, false);
        $this->clock->advanceSeconds(0.15);
        $request->setMethod('POST');
        $this->kernel->terminate($request, $response);
        $this->kernel->terminate($request, $response);

        $duration = $this->metric('http.server.request.duration');
        self::assertSame('s', $duration->unit);
        self::assertInstanceOf(Histogram::class, $duration->data);
        self::assertCount(1, $duration->data->dataPoints);
        foreach ($duration->data->dataPoints as $point) {
            self::assertSame(1, $point->count);
            self::assertSame(0.25, $point->sum);
            self::assertEquals(
                [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10],
                $point->explicitBounds,
            );
            self::assertSame(
                [
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '2',
                    'http.route' => '/orders/{id}',
                    'http.response.status_code' => 200,
                ],
                $point->attributes->toArray(),
            );
        }

        $this->assertNoReports();
    }

    /** @return iterable<string, array{int, string|null}> */
    public static function outcomes(): iterable
    {
        yield 'handled not found' => [404, null];
        yield 'handled success' => [200, null];
        yield 'server error' => [503, '503'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('outcomes')]
    public function handledExceptionsUseTheFinalResponse(int $status, ?string $error): void
    {
        $this->dispatcher->addListener(
            KernelEvents::EXCEPTION,
            static function (ExceptionEvent $event) use ($status): void {
                $event->allowCustomResponseCode();
                $event->setResponse(new Response('', $status));
            },
            100,
        );
        $this->handle($this->request(
            /** @throws \RuntimeException */ static function (): never {
                throw new \RuntimeException('private message');
            },
        ));
        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame($status, $point->attributes->get('http.response.status_code'));
            self::assertSame($error, $point->attributes->get('error.type'));
            self::assertNull($point->attributes->get('http.route'));
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function unhandledExceptionIsMeasuredWithoutInventingAResponse(): void
    {
        $failure = new \RuntimeException('private details');
        try {
            $this->handle($this->request(
                /** @throws \RuntimeException */ static function () use ($failure): never {
                    throw $failure;
                },
            ));
            self::fail('The application exception must escape.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame(1, $point->count);
            self::assertSame(\RuntimeException::class, $point->attributes->get('error.type'));
            self::assertNull($point->attributes->get('http.response.status_code'));
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function shortCircuitStillResolvesTheRouteAndUnknownMethodsAreBounded(): void
    {
        $this->dispatcher->addListener(
            KernelEvents::REQUEST,
            static function (RequestEvent $event): void {
                $event->getRequest()->attributes->set('_route', 'orders');
                $event->setResponse(new Response());
            },
            100,
        );
        $request = Request::create('/orders/987', 'CUSTOM-UNBOUNDED');
        $this->handle($request);
        $metric = $this->metric('http.server.request.duration');
        self::assertInstanceOf(Histogram::class, $metric->data);
        foreach ($metric->data->dataPoints as $point) {
            self::assertSame('_OTHER', $point->attributes->get('http.request.method'));
            self::assertSame('/orders/{id}', $point->attributes->get('http.route'));
            self::assertNull($point->attributes->get('http.request.method_original'));
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function declaredBodySizesAreRecordedWithTheDurationLabels(): void
    {
        $this->handle($this->request(static fn(): Response => new Response('hello'), headers: [
            'Content-Length' => '42',
        ]));

        $duration = MetricPoints::firstHistogram($this->metric('http.server.request.duration'));
        $request = MetricPoints::firstHistogram($this->metric('http.server.request.body.size'));
        $response = MetricPoints::firstHistogram($this->metric('http.server.response.body.size'));
        self::assertSame(42.0, $request->sum);
        self::assertSame(5.0, $response->sum);
        self::assertSame($duration->attributes->toArray(), $request->attributes->toArray());
        self::assertSame($duration->attributes->toArray(), $response->attributes->toArray());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnknownBodySizeIsNotRecordedAsZero(): void
    {
        $this->handle($this->request(
            static fn(): Response => new StreamedResponse(static function (): void {}),
            headers: ['Content-Length' => 'chunked'],
        ));

        self::assertCount(1, MetricPoints::of($this->metric('http.server.request.duration')));
        self::assertSame([], MetricPoints::of($this->metric('http.server.request.body.size')));
        self::assertSame([], MetricPoints::of($this->metric('http.server.response.body.size')));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function protocols(): iterable
    {
        yield 'HTTP/1.0' => ['HTTP/1.0', '1.0'];
        yield 'HTTP/1.1' => ['HTTP/1.1', '1.1'];
        yield 'HTTP/2' => ['HTTP/2', '2'];
        yield 'HTTP/3.0' => ['HTTP/3.0', '3'];
        yield 'unknown is left out' => ['SPDY/3', null];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('protocols')]
    public function theProtocolVersionIsNormalized(string $protocol, ?string $expected): void
    {
        $request = $this->request(static fn(): Response => new Response());
        $request->server->set('SERVER_PROTOCOL', $protocol);
        $this->handle($request);

        self::assertSame(
            $expected,
            MetricPoints::firstHistogram($this->metric('http.server.request.duration'))->attributes->get(
                'network.protocol.version',
            ),
        );
    }
}
