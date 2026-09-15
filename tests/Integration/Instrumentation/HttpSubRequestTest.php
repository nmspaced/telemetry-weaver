<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HttpServerTracingSubscriber::class)]
#[CoversClass(RequestTraceRegistry::class)]
final class HttpSubRequestTest extends HttpTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function resetReleasesNestedOperationsInnermostFirst(): void
    {
        // Held for the length of the test: the registry keys weakly, so an entry whose
        // request nobody holds any more is gone before reset() can walk it.
        $requests = [];

        foreach (['GET', 'POST', 'PUT'] as $method) {
            $request = Request::create('/nested', $method);
            $requests[] = $request;
            $this->scopes->open($request, HttpMethod::from($request));
        }

        $this->scopes->reset();

        self::assertSame(['PUT', 'POST', 'GET'], $this->exportedNames());
        self::assertNull(Context::storage()->scope());
        $this->assertNoReports();
    }

    /**
     * A sub-request continues the trace it is rendered inside, so its parent
     * is the current context, not the incoming headers — those belong to the
     * main request and would start a second root.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aSubRequestIsAnInternalChildOfTheMainRequest(): void
    {
        $this->handle($this->request(
            /** @throws \Throwable */ function (): Response {
                $this->subRequest(static fn(): Response => new Response('fragment'));

                return new Response();
            },
        ));

        $sub = $this->exportedSpan(0);
        $main = $this->exportedSpan(1);
        self::assertSame(SpanKind::KIND_INTERNAL, $sub->getKind());
        self::assertSame(SpanKind::KIND_SERVER, $main->getKind());
        self::assertSame($main->getContext()->getSpanId(), $sub->getParentContext()->getSpanId());
        self::assertSame($main->getContext()->getTraceId(), $sub->getContext()->getTraceId());
        $this->assertNoReports();
    }

    /**
     * The sub-request ends on finish_request — there is no terminate for it —
     * and leaves the main request's span alone.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aSubRequestEndsWithoutClosingTheMainSpan(): void
    {
        $subEndedCount = 0;
        $subSpan = null;

        $request = $this->request(
            /** @throws \Throwable */
            function () use (&$subEndedCount, &$subSpan): Response {
                $this->subRequest(static fn(): Response => new Response('', 404));
                $subEndedCount = \count($this->exported());
                $subSpan = $this->exportedSpan();

                return new Response();
            },
        );
        $this->handle($request);

        self::assertSame(1, $subEndedCount, 'the sub-request ended, the main one had not');
        self::assertInstanceOf(ImmutableSpan::class, $subSpan);
        self::assertSame(404, $subSpan->getAttributes()->get('http.response.status_code'));
        self::assertCount(2, $this->exported());
    }

    /** @throws \Throwable */
    #[Test]
    public function nestedSubRequestsUnwindInOrderAndRestoreTheContext(): void
    {
        $baseline = $this->contextStorage->scope();

        $this->handle($this->request(
            /** @throws \Throwable */ function (): Response {
                $this->subRequest(
                    /** @throws \Throwable */ function (): Response {
                        $this->subRequest(static fn(): Response => new Response('inner'), '/fragment/inner');

                        return new Response('outer');
                    },
                );

                return new Response();
            },
        ));

        $names = $this->exportedNames();
        self::assertSame(['GET', 'GET', 'GET'], $names);

        $inner = $this->exportedSpan(0);
        $outer = $this->exportedSpan(1);
        $main = $this->exportedSpan(2);
        self::assertSame($outer->getContext()->getSpanId(), $inner->getParentContext()->getSpanId());
        self::assertSame($main->getContext()->getSpanId(), $outer->getParentContext()->getSpanId());
        self::assertSame($baseline, $this->contextStorage->scope());
        self::assertNull(Context::storage()->scope());
    }

    /**
     * Excluding a path must not silently reparent its children onto whatever
     * span happens to be current.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anExcludedSubRequestAddsNothing(): void
    {
        $this->boot(excludedPaths: ['/fragment']);

        $this->handle($this->request(
            /** @throws \Throwable */ function (): Response {
                $this->subRequest(static fn(): Response => new Response('fragment'));

                return new Response();
            },
        ));

        self::assertCount(1, $this->exported());
        self::assertSame(SpanKind::KIND_SERVER, $this->exportedSpan()->getKind());
    }
}
