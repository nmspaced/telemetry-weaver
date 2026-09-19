<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerTraceResponseSubscriber;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelResponsePropagation;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingResponsePropagator;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writing the trace back to the caller.
 *
 * The propagator here is a double, because the package does not depend on one: the SDK
 * registry ships `none` and the real `traceresponse` propagator is a separate contrib
 * package. What is under test is therefore the seam — that the bundle asks at a moment
 * when the server span is still current, puts the answer on the response that is actually
 * sent, and survives a propagator that throws.
 */
#[CoversClass(ServerTraceResponseSubscriber::class)]
#[CoversClass(OtelResponsePropagation::class)]
final class HttpResponsePropagationTest extends HttpTelemetryTestCase
{
    private RecordingResponsePropagator $propagator;

    #[\Override]
    protected function setUp(): void
    {
        $this->propagator = new RecordingResponsePropagator();
        parent::setUp();
    }

    /** @throws \Throwable */
    #[Test]
    public function theServerSpanIsWrittenBackOntoTheResponse(): void
    {
        $this->bootWith(new RecordingResponsePropagator());

        $response = $this->handle($this->request(static fn(): Response => new Response()));

        $span = $this->exportedSpan();
        self::assertSame(
            \sprintf('00-%s-%s-01', $span->getContext()->getTraceId(), $span->getContext()->getSpanId()),
            $response->headers->get('traceresponse'),
            'the header names the server span, not its remote parent',
        );
        $this->assertNoReports();
    }

    /**
     * A sub-request's response is rendered into the page rather than sent, so headers on it
     * reach nobody — and asking once per sub-request would also overwrite the main
     * request's header with an internal span.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aSubRequestIsNotAskedFor(): void
    {
        $this->bootWith(new RecordingResponsePropagator());

        $this->handle($this->request(
            /** @throws \Throwable */
            function (): Response {
                $this->subRequest(static fn(): Response => new Response('fragment'));

                return new Response();
            },
        ));

        self::assertSame(1, $this->propagator->calls, 'only the response that is sent is propagated into');
    }

    /** @throws \Throwable */
    #[Test]
    public function anExcludedRequestIsNotPropagatedInto(): void
    {
        $this->bootWith(new RecordingResponsePropagator(), excludedPaths: ['/health']);

        $response = $this->handle($this->request(static fn(): Response => new Response('ok'), '/health'));

        self::assertSame(0, $this->propagator->calls);
        self::assertFalse($response->headers->has('traceresponse'));
    }

    /**
     * Telemetry does not get to break a response that is otherwise fine.
     *
     * @throws \Throwable
     */
    #[Test]
    public function aThrowingPropagatorLeavesTheResponseIntact(): void
    {
        $this->bootWith(new RecordingResponsePropagator(new \RuntimeException('propagator exploded')));

        $response = $this->handle($this->request(static fn(): Response => new Response('body')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body', $response->getContent());
        self::assertFalse($response->headers->has('traceresponse'));
        self::assertNotSame([], $this->logger->messages(), 'the failure is reported rather than swallowed silently');
    }

    /** @param list<non-empty-string> $excludedPaths */
    private function bootWith(RecordingResponsePropagator $propagator, array $excludedPaths = []): void
    {
        $this->propagator = $propagator;
        $this->responsePropagation = new OtelResponsePropagation($propagator, $this->contextStorage, $this->reporter);
        $this->boot(excludedPaths: $excludedPaths);
    }
}
