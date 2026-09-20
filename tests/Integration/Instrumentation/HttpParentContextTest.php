<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ParentContext::class)]
#[CoversClass(OtelPropagation::class)]
final class HttpParentContextTest extends HttpTelemetryTestCase
{
    private const string TRACEPARENT = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    /** @throws \Throwable */
    #[Test]
    public function aTraceparentContinuesTheCallersTrace(): void
    {
        $this->handle($this->request(static fn(): Response => new Response(), headers: [
            'traceparent' => self::TRACEPARENT,
        ]));

        $span = $this->exportedSpan();
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $span->getContext()->getTraceId());
        self::assertSame('b7ad6b7169203331', $span->getParentContext()->getSpanId());
    }

    /**
     * Extraction is based on the root context, not the current one. Without
     * that, a request with no traceparent would adopt whatever third-party
     * instrumentation left on top of the stack.
     *
     * @throws \Throwable
     */
    #[Test]
    public function withoutATraceparentAnAmbientSpanIsNotInherited(): void
    {
        $leaked = $this->leak('left-behind-by-someone-else');

        $this->handle($this->request(static fn(): Response => new Response()));

        $span = $this->exportedSpan();
        self::assertFalse($span->getParentContext()->isValid());
        self::assertNotSame($leaked->getContext()->getTraceId(), $span->getContext()->getTraceId());

        $leaked->end();
    }

    /**
     * A sub-request is not a process boundary, so the headers are not a carrier for it —
     * they still describe the hop that reached the main request. Re-extracting them would
     * make the sub-request a second child of the *caller's* span, next to the server span
     * rather than inside it, and the request would export two disconnected subtrees.
     *
     * The existing sub-request coverage cannot see this: it sends no traceparent, so a
     * re-extraction would find nothing and fall back to the current context anyway.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aSubRequestDoesNotReExtractTheIncomingTraceparent(): void
    {
        $this->handle($this->request(
            /** @throws \Throwable */
            function (): Response {
                $this->subRequest(static fn(): Response => new Response('fragment'), headers: [
                    'traceparent' => self::TRACEPARENT,
                ]);

                return new Response();
            },
            headers: ['traceparent' => self::TRACEPARENT],
        ));

        $sub = $this->exportedSpan(0);
        $main = $this->exportedSpan(1);
        self::assertSame($main->getContext()->getSpanId(), $sub->getParentContext()->getSpanId());
        self::assertNotSame('b7ad6b7169203331', $sub->getParentContext()->getSpanId());
        $this->assertNoReports();
    }

    /**
     * A caller that sends nonsense must cost the request nothing. The propagator returns
     * the base context unchanged rather than throwing, so the span is simply a root.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aMalformedTraceparentIsIgnoredAndTheRequestStillSucceeds(): void
    {
        $response = $this->handle($this->request(static fn(): Response => new Response('ok'), headers: [
            'traceparent' => 'not-a-traceparent',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());

        $span = $this->exportedSpan();
        self::assertFalse($span->getParentContext()->isValid());
        self::assertTrue($span->getContext()->isValid());
        $this->assertNoReports();
    }

    /**
     * A propagator that throws — a custom one, or one misconfigured at the SDK level —
     * used to take the request with it: extraction happens on `kernel.request`, before the
     * operation exists, so the exception left the listener and a working request answered
     * 500. Telemetry may cost a trace, never a response.
     *
     * The fallback is a *new* root rather than the ambient context. In a worker the
     * ambient context is the previous unit of work, so inheriting it would answer a
     * propagation failure by filing this request under the last one.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aThrowingPropagatorCostsTheTraceAndNotTheRequest(): void
    {
        $propagator = $this->createStub(TextMapPropagatorInterface::class);
        $propagator->method('extract')->willThrowException(new \RuntimeException('propagator unavailable'));
        $this->boot(propagator: $propagator);

        $leaked = $this->leak('left-behind-by-someone-else');

        $response = $this->handle($this->request(static fn(): Response => new Response('ok'), headers: [
            'traceparent' => self::TRACEPARENT,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());

        $span = $this->exportedSpan();
        self::assertFalse($span->getParentContext()->isValid());
        self::assertNotSame($leaked->getContext()->getTraceId(), $span->getContext()->getTraceId());
        self::assertNotSame('0af7651916cd43dd8448eb211c80319c', $span->getContext()->getTraceId());

        $leaked->end();
    }
}
