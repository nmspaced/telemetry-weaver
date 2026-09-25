<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTrace;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(HttpServerTracingSubscriber::class)]
#[CoversClass(RequestTrace::class)]
final class HttpLifecycleTest extends HttpTelemetryTestCase
{
    /** @return iterable<string, array{int, string, ?string}> */
    public static function responses(): iterable
    {
        yield 'success' => [200, StatusCode::STATUS_UNSET, null];
        yield 'redirect' => [302, StatusCode::STATUS_UNSET, null];
        yield 'not found' => [404, StatusCode::STATUS_UNSET, null];
        yield 'server error' => [500, StatusCode::STATUS_ERROR, '500'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('responses')]
    public function theResponseCodeDecidesTheStatus(int $code, string $status, ?string $error): void
    {
        $this->handle($this->request(static fn(): Response => new Response('', $code)));

        $span = $this->exportedSpan();
        self::assertSame($code, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame($status, $span->getStatus()->getCode());
        self::assertSame($error, $span->getAttributes()->get('error.type'));
        self::assertSame(SpanKind::KIND_SERVER, $span->getKind());
        self::assertNull(Context::storage()->scope());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function theRouteTemplateNamesTheSpan(): void
    {
        $this->boot(routes: ['order_show' => '/orders/{id}']);

        $this->handle($this->request(static fn(): Response => new Response(), attributes: ['_route' => 'order_show']));

        $span = $this->exportedSpan();
        self::assertSame('GET /orders/{id}', $span->getName());
        self::assertSame('/orders/{id}', $span->getAttributes()->get('http.route'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnroutedRequestKeepsTheBareMethod(): void
    {
        $this->handle($this->request(static fn(): Response => new Response()));

        $span = $this->exportedSpan();
        self::assertSame('GET', $span->getName());
        self::assertNull($span->getAttributes()->get('http.route'));
    }

    /** @throws \Throwable */
    #[Test]
    public function aShortCircuitedRequestStillGetsItsRoute(): void
    {
        $this->boot(routes: ['order_show' => '/orders/{id}']);
        $this->dispatcher->addListener(
            KernelEvents::REQUEST,
            static function (RequestEvent $event): void {
                $event->getRequest()->attributes->set('_route', 'order_show');
                $event->setResponse(new Response('', 302));
            },
            1024,
        );

        $this->handle($this->request(static fn(): Response => new Response()));

        $span = $this->exportedSpan();
        self::assertSame('GET /orders/{id}', $span->getName());
        self::assertSame(302, $span->getAttributes()->get('http.response.status_code'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anExceptionTurnedIntoAClientErrorLeavesTheSpanClean(): void
    {
        $this->dispatcher->addListener(
            KernelEvents::EXCEPTION,
            static fn(ExceptionEvent $event): null => $event->setResponse(new Response('', 404)),
            -100,
        );

        $this->handle($this->request(
            /** @throws NotFoundHttpException always */
            static fn(): Response => throw new NotFoundHttpException('no such order'),
        ));

        $span = $this->exportedSpan();
        self::assertSame(404, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertNull($span->getAttributes()->get('error.type'));
        self::assertSame([], $span->getEvents());
    }

    /** @throws \Throwable */
    #[Test]
    public function anExceptionTurnedIntoAServerErrorIsAnError(): void
    {
        $this->dispatcher->addListener(
            KernelEvents::EXCEPTION,
            static fn(ExceptionEvent $event): null => $event->setResponse(new Response('', 500)),
            -100,
        );

        $this->handle($this->request(
            /** @throws \RuntimeException always */
            static fn(): Response => throw new \RuntimeException('broken'),
        ));

        $span = $this->exportedSpan();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('500', $span->getAttributes()->get('error.type'));
        self::assertSame('exception', $this->firstEventName($span));
    }

    /** @throws \Throwable */
    #[Test]
    public function aLateReplacementOfA500DoesNotLeaveTheErrorStuck(): void
    {
        $request = $this->request(static fn(): Response => new Response('', 500));
        $this->kernel->handle($request);
        $this->kernel->terminate($request, new Response('', 200));

        $span = $this->exportedSpan();
        self::assertSame(200, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertNull($span->getAttributes()->get('error.type'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anExcludedPathIsNotInstrumented(): void
    {
        $this->boot(excludedPaths: ['/health']);

        $this->handle($this->request(static fn(): Response => new Response(), uri: '/health/live'));

        self::assertSame([], $this->exported());
        self::assertNull(Context::storage()->scope());
    }
}
