<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTrace;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The outcome half of a server span: what a caught exception and an observed status together say
 * about the span, decided once at complete().
 */
#[CoversClass(RequestTrace::class)]
final class RequestTraceOutcomeTest extends TestCase
{
    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private InMemoryExporter $exporter;

    private TracerProvider $provider;

    private SpanOpener $spans;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousStorage = Context::storage();
        Context::setStorage(new ContextStorage());
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->spans = new SpanOpener(
            $this->provider->getTracer('test'),
            Context::storage(),
            new InstrumentationFailureReporter(new RecordingLogger()),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        while (($scope = Context::storage()->scope()) !== null) {
            $scope->detach();
        }

        $this->provider->shutdown();
        Context::setStorage($this->previousStorage);
    }

    #[Test]
    public function anExceptionAnsweredWithA404LeavesTheSpanClean(): void
    {
        $trace = $this->trace();
        $trace->exception(new NotFoundHttpException('no such order'));
        $trace->response(new Response(status: 404));
        $trace->complete();

        $span = $this->span();
        self::assertSame(404, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame([], $span->getEvents(), 'a 404 is not a failure of the server');
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertNull($span->getAttributes()->get('error.type'));
    }

    #[Test]
    public function anExceptionAnsweredWithA500IsRecordedAndErrorsTheSpan(): void
    {
        $trace = $this->trace();
        $trace->exception(new \RuntimeException('the database is gone'));
        $trace->response(new Response(status: 500));
        $trace->complete();

        $span = $this->span();
        self::assertSame(500, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame('exception', ($span->getEvents()[0] ?? null)?->getName());
        self::assertSame('500', $span->getAttributes()->get('error.type'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
    }

    #[Test]
    public function theMinimumStatusMovesTheEventAndNotTheErrorStatus(): void
    {
        $trace = $this->trace(recordExceptionMinStatus: 400);
        $trace->exception(new NotFoundHttpException('no such order'));
        $trace->response(new Response(status: 404));
        $trace->complete();

        $span = $this->span();
        self::assertSame('exception', ($span->getEvents()[0] ?? null)?->getName());
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    #[Test]
    public function anExceptionWithoutAResponseErrorsTheSpanWithItsType(): void
    {
        $trace = $this->trace();
        $trace->exception(new \RuntimeException('nothing answered'));
        $trace->abandon();

        $span = $this->span();
        self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
        self::assertSame('exception', ($span->getEvents()[0] ?? null)?->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertNull($span->getAttributes()->get('http.response.status_code'));
    }

    #[Test]
    public function anAbandonedRequestWithoutAnExceptionClaimsNothing(): void
    {
        $this->trace()->abandon();

        $span = $this->span();
        self::assertSame([], $span->getEvents());
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertNull($span->getAttributes()->get('error.type'));
    }

    /** @param int<400, 599> $recordExceptionMinStatus */
    private function trace(int $recordExceptionMinStatus = 500): RequestTrace
    {
        return new RequestTrace(
            TelemetryFactory::tracing($this->spans)->boundary('GET')->start(),
            'GET',
            $recordExceptionMinStatus,
        );
    }

    private function span(): ImmutableSpan
    {
        $span = $this->exporter->getSpans()[0] ?? null;
        self::assertInstanceOf(ImmutableSpan::class, $span, 'the span has to be ended');

        return $span;
    }
}
