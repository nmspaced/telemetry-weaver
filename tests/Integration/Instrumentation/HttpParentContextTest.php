<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Entry;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ParentContext::class)]
#[CoversClass(RootTrace::class)]
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
     * `tracestate` is a list with an explicit combining rule, and a runtime that does not
     * fold duplicate headers — any PSR-7 bridge, so RoadRunner — hands the `HeaderBag` one
     * entry per header. Reading only the first silently drops every vendor after it.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aTracestateSentAsSeveralHeadersKeepsEveryVendorInOrder(): void
    {
        $this->handle($this->request(static fn(): Response => new Response(), headers: [
            'traceparent' => self::TRACEPARENT,
            'tracestate' => ['vendor1=value1', 'vendor2=value2'],
        ]));

        $traceState = $this->exportedSpan()->getContext()->getTraceState();

        self::assertNotNull($traceState);
        self::assertSame('value1', $traceState->get('vendor1'));
        self::assertSame('value2', $traceState->get('vendor2'), 'the second header was not dropped');
        $this->assertNoReports();
    }

    /**
     * The same for baggage, which W3C defines as a comma-separated list whose entries may
     * arrive as separate headers. A tenant id in the second one is not optional.
     *
     * @throws \Throwable
     */
    #[Test]
    public function baggageSentAsSeveralHeadersKeepsEveryEntry(): void
    {
        $this->boot(propagator: new MultiTextMapPropagator([
            TraceContextPropagator::getInstance(),
            BaggagePropagator::getInstance(),
        ]));

        $entries = [];
        $this->handle($this->request(static function () use (&$entries): Response {
            foreach (Baggage::getCurrent()->getAll() as $key => $entry) {
                $entries[$key] = $entry instanceof Entry && \is_string($entry->getValue()) ? $entry->getValue() : null;
            }

            return new Response();
        }, headers: [
            'traceparent' => self::TRACEPARENT,
            'baggage' => ['tenant=one', 'region=west'],
        ]));

        self::assertSame(['tenant' => 'one', 'region' => 'west'], $entries);
        $this->assertNoReports();
    }

    /**
     * `http_server: traces: false` removes the server span, not the request's context: what
     * the application sends downstream still continues the caller's trace and carries its
     * baggage.
     *
     * @throws \Throwable
     */
    #[Test]
    public function withServerSpansOffTheIncomingContextStillReachesDownstreamCalls(): void
    {
        $propagator = new MultiTextMapPropagator([
            TraceContextPropagator::getInstance(),
            BaggagePropagator::getInstance(),
        ]);
        $this->serverSpans = $this->spans->suppressed();
        $this->boot(propagator: $propagator);

        $outgoing = [];
        $this->handle($this->request(function () use ($propagator, &$outgoing): Response {
            $propagator->inject($outgoing, null, $this->contextStorage->current());

            return new Response();
        }, headers: ['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=one']));

        self::assertSame(['traceparent' => self::TRACEPARENT, 'baggage' => 'tenant=one'], $outgoing);
        self::assertSame([], $this->exportedNames(), 'and no server span was recorded');
        self::assertNull($this->contextStorage->scope(), 'the request released its context');
        $this->assertNoReports();
    }

    /**
     * `traceparent` is the one field a valid request carries at most once. Two of them are
     * two callers disagreeing about the parent, not a longer header: joining them would
     * assemble a syntactically valid traceparent out of an ambiguous request, and picking
     * one is a coin toss. The request becomes a new root, which is what every other
     * unusable boundary means here — and baggage, which is independent of tracing, still
     * arrives.
     *
     * @throws \Throwable
     */
    #[Test]
    public function twoTraceparentsAreRefusedRatherThanCombined(): void
    {
        $this->handle($this->request(static fn(): Response => new Response(), headers: [
            'traceparent' => [self::TRACEPARENT, '00-' . \str_repeat('b', 32) . '-' . \str_repeat('c', 16) . '-01'],
        ]));

        $span = $this->exportedSpan();
        self::assertFalse($span->getParentContext()->isValid());
        self::assertNotSame('0af7651916cd43dd8448eb211c80319c', $span->getContext()->getTraceId());
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
        self::assertSame(1, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function aThrowingReplacementPropagationStartsAFreshRootAndPreservesTheResponse(): void
    {
        $propagation = $this->createStub(Propagation::class);
        $propagation->method('extract')->willThrowException(new \RuntimeException('propagation unavailable'));
        $this->boot(propagation: $propagation);
        $leaked = $this->leak('unrelated');
        $expected = new Response('delivered', 201);
        $request = $this->request(static fn(): Response => $expected, headers: ['traceparent' => self::TRACEPARENT]);

        self::assertSame($expected, $this->handle($request));
        $span = $this->exportedSpan();
        self::assertFalse($span->getParentContext()->isValid());
        self::assertNotSame($leaked->getContext()->getTraceId(), $span->getContext()->getTraceId());
        self::assertNull($this->scopes->of($request));
        self::assertSame($leaked->getContext()->getSpanId(), $this->activeTrace()?->spanId);
        self::assertSame(1, $this->reporter->total());
        $leaked->end();
    }
}
