<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class HttpRouteFailureTest extends HttpTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    #[DataProvider('failureStages')]
    public function routeFailuresPreserveTheResponseAndReleaseTheRequest(
        int $firstFailingCall,
        int $expectedReports,
    ): void {
        $calls = 0;
        $routes = $this->createMock(RouteTemplateProvider::class);
        $routes
            ->expects(self::exactly(2))
            ->method('resolve')
            ->willReturnCallback(
                /** @throws \RuntimeException */
                static function () use (&$calls, $firstFailingCall): string {
                    if (++$calls < $firstFailingCall) {
                        return '/orders/{id}';
                    }

                    throw new \RuntimeException('route provider unavailable');
                },
            );
        $this->boot(routeTemplateProvider: $routes);
        $expected = new Response('delivered', 202);
        $request = $this->request(static fn(): Response => $expected, attributes: ['_route' => 'orders']);

        self::assertSame($expected, $this->handle($request));
        self::assertSame(202, $this->exportedSpan()->getAttributes()->get('http.response.status_code'));
        self::assertSame($expectedReports, $this->reporter->total());
        self::assertNull($this->scopes->of($request));
        self::assertNull($this->activeTrace());
    }

    /** @throws \Throwable */
    #[Test]
    public function routeFailureDoesNotReplaceTheControllersException(): void
    {
        $routes = $this->createStub(RouteTemplateProvider::class);
        $routes->method('resolve')->willThrowException(new \RuntimeException('route provider unavailable'));
        $this->boot(routeTemplateProvider: $routes);
        $failure = new \LogicException('controller failed');
        $expected = new Response('application error', 503);
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event) use (
            $failure,
            $expected,
        ): void {
            self::assertSame($failure, $event->getThrowable());
            $event->setResponse($expected);
        });
        $request = $this->request(
            /** @throws \LogicException */
            static fn(): Response => throw $failure,
            attributes: ['_route' => 'orders'],
        );

        self::assertSame($expected, $this->handle($request));
        self::assertSame(503, $this->exportedSpan()->getAttributes()->get('http.response.status_code'));
        self::assertNull($this->scopes->of($request));
        self::assertNull($this->activeTrace());
    }

    /** @return iterable<string, array{int, int}> */
    public static function failureStages(): iterable
    {
        yield 'request and response enrichment fail' => [1, 2];
        yield 'only response enrichment fails' => [2, 1];
    }
}
