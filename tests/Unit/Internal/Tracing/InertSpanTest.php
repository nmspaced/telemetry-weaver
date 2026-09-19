<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Tracing\InertSpan;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOwner;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The owner an operation gets when tracing is off or its span is suppressed.
 *
 * Two things it must keep doing despite recording nothing, because they are what a
 * suppressed operation still contributes: the trace its duration belongs to, and the
 * `error.type` that duration is labelled with. A metric-only failure that arrives unlabelled
 * is indistinguishable from a success.
 */
#[CoversClass(InertSpan::class)]
#[CoversClass(NoOpSpanOpener::class)]
final class InertSpanTest extends TestCase
{
    #[Test]
    public function itWritesNowhereAndNamesNoTrace(): void
    {
        $span = new InertSpan('probe');

        $span->attribute('app.order', 42);
        $span->event('checked');
        $span->rename('other');
        $span->recordException(new \RuntimeException('boom'));

        self::assertFalse($span->isRecording());
        self::assertNull($span->traceId());
        self::assertNull($span->spanId());
        self::assertSame('probe', $span->name());
    }

    /**
     * Owner and view are the same object, because with no span behind it there is nothing to
     * revoke: a retained view can no more reach the next request than it can reach this one.
     */
    #[Test]
    public function theViewIsTheOwner(): void
    {
        $span = new InertSpan('probe');

        self::assertSame($span, $span->view());
    }

    #[Test]
    public function theCorrelationSurvivesSoTheDurationStillNamesItsTrace(): void
    {
        $correlation = new class implements TraceCorrelation {};
        $span = new InertSpan('probe', $correlation);

        $span->finish();

        self::assertSame($correlation, $span->correlation(), 'a suppressed operation still ran inside a trace');
    }

    #[Test]
    public function theErrorTypeIsRememberedFromTheDescription(): void
    {
        $span = new InertSpan('probe');

        $span->rememberErrorType(['error.type' => 'payment.declined']);

        self::assertSame('payment.declined', $span->errorType());
    }

    /**
     * The same rule the recording view follows: a description built in two calls must not
     * lose what the first one declared.
     */
    #[Test]
    public function anAbsentKeyLeavesWhatWasRemembered(): void
    {
        $span = new InertSpan('probe');
        $span->rememberErrorType(['error.type' => 'payment.declined']);

        $span->rememberErrorType(['app.retries' => 2]);

        self::assertSame('payment.declined', $span->errorType());
    }

    #[Test]
    public function failNamesTheOutcome(): void
    {
        $span = new InertSpan('probe');

        $span->fail('timeout');

        self::assertSame('timeout', $span->errorType());
    }

    /**
     * Detaching and finishing something that was never activated or started must be a no-op,
     * not a special case every caller has to know about.
     */
    #[Test]
    public function finishingIsIdempotentAndHarmless(): void
    {
        $span = new InertSpan('probe');

        $span->detach();
        $span->finish();
        $span->finish();

        self::assertFalse($span->isRecording());
    }

    #[Test]
    public function theDisabledOpenerHandsOutOneWithNoCorrelation(): void
    {
        $owner = NoOpSpanOpener::disabled()->open('probe', new SpanOptions());

        self::assertInstanceOf(SpanOwner::class, $owner);
        self::assertNull($owner->correlation(), 'nothing is being traced, so there is nothing to point at');
    }
}
