<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerSpanAttributes;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** Requests the server instrumentation did not expect are answered normally and leave nothing behind. */
#[CoversClass(HttpServerTracingSubscriber::class)]
#[CoversClass(RequestTraceRegistry::class)]
#[CoversClass(ServerSpanAttributes::class)]
final class HttpServerEdgeCaseTest extends HttpTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aSuspiciousHostIsNotRecordedAndDoesNotFailTheRequest(): void
    {
        $response = $this->handle($this->request(static fn(): Response => new Response('ok'), headers: [
            'Host' => 'bad host!',
        ]));

        self::assertSame('ok', $response->getContent());
        self::assertNull($this->exportedSpan()->getAttributes()->get('server.address'));
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function aRequestTheServerNeverSawIsIgnored(): void
    {
        $request = Request::create('/orders/7');

        $this->dispatcher->dispatch(
            new FinishRequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST),
            KernelEvents::FINISH_REQUEST,
        );
        $this->scopes->route($request, new RequestPolicy());

        self::assertNull($this->scopes->of($request));
        self::assertSame([], $this->exported());
        self::assertNull($this->contextStorage->scope());
        $this->assertNoReports();
    }
}
