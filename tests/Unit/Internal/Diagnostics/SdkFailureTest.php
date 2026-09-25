<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Diagnostics;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTrace;
use Nmspaced\TelemetryWeaver\Internal\Metrics\NoopDuration;
use Nmspaced\TelemetryWeaver\Internal\Operation\ActiveOperation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * An SDK that misbehaves from inside a span — not at creation, where SpanOpener already answers
 * with an inert span, but afterwards, on a span the caller is holding.
 */
#[CoversClass(SpanOpener::class)]
#[CoversClass(OwnedSpan::class)]
#[CoversClass(RequestTrace::class)]
final class SdkFailureTest extends TelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aContextReadFailureReleasesTheActivationAndEndsTheSpan(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('storeInContext')->willReturn(Context::getRoot());
        $span->method('getContext')->willThrowException(new \RuntimeException('context read failed'));
        $span->expects(self::once())->method('end');
        $span->expects(self::never())->method('setAttribute');
        $baseline = $this->contextStorage->scope();

        $owner = $this->openerFor($span)->open('operation', new SpanOptions());
        $owner->enrich(static fn(SpanInterface $target): SpanInterface => $target->setAttribute('ignored', true));

        self::assertTrue($owner->isFinished());
        self::assertFalse($owner->spanContext()->isValid());
        self::assertSame($baseline, $this->contextStorage->scope());
        self::assertStringContainsString('context read failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aRecordingCheckFailureIsReportedAndTheSpanStillEnds(): void
    {
        $owner = $this->openerFor($this->spanWithBrokenRecordingCheck())->open('operation', new SpanOptions());
        $owner->enrich(static fn(SpanInterface $span): SpanInterface => $span->setAttribute('key', 'value'));
        $owner->finish();

        self::assertTrue($owner->isFinished());
        self::assertNull($this->contextStorage->scope());
        self::assertStringContainsString('recording check failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aRecordingCheckFailureDoesNotPreventHttpCleanup(): void
    {
        $owner = $this->openerFor($this->spanWithBrokenRecordingCheck())->open('GET', new SpanOptions());
        $trace = new RequestTrace(
            ActiveOperation::owning($owner, new NoopDuration(), [], $this->reporter, new OtelBaggageReader()),
            'GET',
        );
        $trace->response(new Response('', 500));

        $trace->complete();

        self::assertTrue($owner->isFinished());
        self::assertNull($this->contextStorage->scope());
    }

    /** @throws \Throwable */
    private function openerFor(SpanInterface $span): SpanOpener
    {
        $builder = $this->createStub(SpanBuilderInterface::class);
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttributes')->willReturnSelf();
        $builder->method('setParent')->willReturnSelf();
        $builder->method('startSpan')->willReturn($span);

        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);

        return new SpanOpener($tracer, $this->contextStorage, $this->reporter);
    }

    /** @throws \Throwable */
    private function spanWithBrokenRecordingCheck(): SpanInterface&MockObject
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('getContext')->willReturn(Span::getInvalid()->getContext());
        $span->method('storeInContext')->willReturn(Context::getRoot());
        $span->method('isRecording')->willThrowException(new \RuntimeException('recording check failed'));
        $span->expects(self::once())->method('end');

        return $span;
    }
}
