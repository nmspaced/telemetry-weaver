<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanView;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanView::class)]
final class SpanViewTest extends TestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aBrokenSpanIsReportedInsteadOfThrown(): void
    {
        $failure = new \RuntimeException('span is broken');
        $span = $this->createStub(SpanInterface::class);
        $span->method('isRecording')->willThrowException($failure);
        $span->method('getContext')->willThrowException($failure);
        $logger = new RecordingLogger();
        $view = SpanView::borrowed($span, new InstrumentationFailureReporter($logger));

        $view->event('retry');
        $view->rename('renamed');
        self::assertFalse($view->isRecording());
        self::assertNull($view->traceId());
        self::assertNull($view->spanId());

        $failures = [
            'Span enrichment failed',
            'Span enrichment failed',
            'Span recording state read failed',
            'Span identity read failed',
            'Span identity read failed',
        ];
        self::assertSame(
            \array_map(
                static fn(string $what, int $total): string => \sprintf(
                    'OpenTelemetry lifecycle: %s at "%s": span is broken (%d total in this process)',
                    $what,
                    SpanView::class,
                    $total,
                ),
                $failures,
                \range(1, \count($failures)),
            ),
            $logger->messages(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aBrokenSpanWithoutAReporterStaysSilent(): void
    {
        $span = $this->createStub(SpanInterface::class);
        $span->method('isRecording')->willThrowException(new \RuntimeException('span is broken'));
        $span->method('getContext')->willThrowException(new \RuntimeException('span is broken'));
        $view = SpanView::borrowed($span, null);

        $view->fail('timeout');
        self::assertFalse($view->isRecording());
        self::assertNull($view->spanId());
        self::assertSame('timeout', $view->errorType());
    }

    /** @throws \Throwable */
    #[Test]
    public function anEmptyIdOnAValidContextReadsAsNull(): void
    {
        $context = $this->createStub(SpanContextInterface::class);
        $context->method('isValid')->willReturn(true);
        $context->method('getTraceId')->willReturn('');
        $context->method('getSpanId')->willReturn('00f067aa0ba902b7');
        $span = $this->createStub(SpanInterface::class);
        $span->method('getContext')->willReturn($context);
        $view = SpanView::borrowed($span, null);

        self::assertNull($view->traceId());
        self::assertSame('00f067aa0ba902b7', $view->spanId());
    }

    /** @throws \Throwable */
    #[Test]
    public function eventsReachARecordingSpanUntilTheViewIsReleased(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span->method('isRecording')->willReturn(true);
        $span->expects(self::once())->method('addEvent')->with('retry', ['attempt' => 2])->willReturnSelf();
        $view = SpanView::borrowed($span, null);

        $view->event('retry', ['attempt' => 2]);
        $view->release();
        $view->event('retry', ['attempt' => 3]);

        self::assertFalse($view->isRecording());
    }
}
