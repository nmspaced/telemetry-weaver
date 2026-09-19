<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * Core `Telemetry` identity and lifecycle: trace()/operation() borrow rather than transfer
 * ownership, a plan is immutable and resolves its ambient parent only at start, and a
 * detached/abandoned/retained view cannot outlive or corrupt the SDK span it once named.
 * Error and `fail()` semantics live in {@see PublicTelemetryFailureTest}; `SafeMetrics`
 * degradation in {@see SafeMetricsResilienceTest}; fiber/leak safety in
 * {@see PublicTelemetryConcurrencyTest}.
 */
final class PublicTelemetryTest extends PublicTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
    #[Test]
    public function tracePreservesIdentityAndOnlyLendsEnrichment(): void
    {
        $result = new \stdClass();
        $telemetry = $this->telemetry();
        self::assertSame($result, $telemetry->trace('checkout', static function (Span $span) use ($result): object {
            self::assertFalse(\method_exists($span, 'finish'));
            self::assertFalse(\method_exists($span, 'detach'));
            $span->attribute('order.id', '42');

            return $result;
        }));
        self::assertSame('42', $this->exportedSpan()->getAttributes()->get('order.id'));
        self::assertNull($this->contextStorage->scope());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function planIsImmutableAndResolvesAmbientParentOnlyAtStart(): void
    {
        $telemetry = $this->telemetry();
        $base = $telemetry->operation('child')->attributes(['variant' => 'original']);
        $changed = $base->attributes(['variant' => 'changed'])->kind(SpanKind::Client);
        self::assertSame([], $this->exported());
        self::assertNull($this->contextStorage->scope());

        $parent = $telemetry->operation('parent')->start();
        $parentId = $parent->span()->spanId();
        $changed->run(static function (OperationContext $context): void {
            self::assertNotInstanceOf(RunningOperation::class, $context);
        });
        $base->run(static fn(): int => 1);
        $parent->finish();
        self::assertSame($parentId, $this->exportedSpan()->getParentContext()->getSpanId());
        self::assertSame('changed', $this->exportedSpan()->getAttributes()->get('variant'));
        self::assertSame('original', $this->exportedSpan(1)->getAttributes()->get('variant'));
        self::assertSame(OtelSpanKind::KIND_CLIENT, $this->exportedSpan()->getKind());
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function explicitRootPreservesTheOuterOwner(): void
    {
        $telemetry = $this->telemetry();
        $outer = $telemetry->operation('outer')->start();
        $id = $outer->span()->spanId();
        $outer->span()->attribute('borrowed', true);
        $telemetry
            ->boundary('root')
            ->from($this->rootTrace())
            ->run(static fn(): bool => true);
        self::assertFalse($this->exportedSpan()->getParentContext()->isValid());
        self::assertSame($id, $this->activeTrace()['span_id'] ?? null, 'the root operation restored the outer one');
        $outer->finish();
        self::assertTrue($this->exportedSpan(1)->getAttributes()->get('borrowed'));
    }

    #[Test]
    public function detachKeepsDurationOpenAndCompletionIsIdempotent(): void
    {
        $telemetry = $this->telemetry();
        $operation = $telemetry->boundary('request')->duration($this->duration($telemetry))->start();
        $operation->detach();
        self::assertNull($this->contextStorage->scope());
        self::assertSame([], $this->exported());
        $this->clock->advanceSeconds(0.5);
        $operation->metricAttributes(['route' => '/orders/{id}']);
        $operation->finish();
        $operation->finish(new \RuntimeException('too late'));
        $operation->abandon();
        $operation->span()->attribute('too_late', true);
        self::assertCount(1, $this->exported());
        self::assertNull($this->exportedSpan()->getAttributes()->get('too_late'));
        self::assertSame(0.5, $this->metricPoint()->sum);
    }

    #[Test]
    public function abandonDiscardsMeasurementAndRevokesTheBorrowedView(): void
    {
        $telemetry = $this->telemetry();
        $operation = $telemetry->operation('abandoned')->duration($this->duration($telemetry))->start();
        $view = $operation->span();
        $raw = \WeakReference::create(OtelSpan::getCurrent());
        $operation->abandon();
        $operation->finish(new \RuntimeException());
        self::assertNull($raw->get(), 'Keeping a finished handle must not retain the SDK span.');
        self::assertFalse($view->isRecording());
        self::assertSame([], MetricPoints::of($this->metric()));
        self::assertNull($this->contextStorage->scope());
    }

    /**
     * A `Span` is a borrowed view, and a shared service that keeps one — against the
     * docblock, but it will happen — must not keep the SDK span of request A alive in a
     * worker, nor be able to write into request B.
     */
    #[Test]
    public function aRetainedSpanViewDoesNotKeepTheSdkSpanAliveOrReachTheNextRequest(): void
    {
        $telemetry = $this->telemetry();
        $a = $telemetry->operation('request A')->start();
        $retained = $a->span();
        $raw = \WeakReference::create(OtelSpan::getCurrent());

        self::assertNotNull($retained->spanId());
        $retained->attribute('inside', true);
        $a->finish();
        unset($a);

        self::assertNull($raw->get(), "the view must not be what keeps request A's span alive");
        self::assertFalse($retained->isRecording());

        $b = $telemetry->operation('request B')->start();
        $retained->attribute('leaked', true);
        $retained->fail('leaked');

        $b->finish();

        self::assertTrue($this->exportedSpan()->getAttributes()->get('inside'), 'it worked while A was live');
        self::assertNull($this->exportedSpan(1)->getAttributes()->get('leaked'));
        self::assertSame(StatusCode::STATUS_UNSET, $this->exportedSpan(1)->getStatus()->getCode());
        self::assertNull($retained->spanId(), 'a released view names no span at all');
        $this->assertNoReports();
    }

    /**
     * A link survives the rest of the fluent description, and an invalid context — what
     * `Span::getCurrent()` returns outside any span — adds nothing.
     *
     * @throws \Throwable
     */
    #[Test]
    public function linksAreKeptAcrossTheDescriptionAndInvalidOnesAreIgnored(): void
    {
        $telemetry = $this->telemetry();
        $related = $telemetry->operation('related')->start();
        $relatedTraceId = $related->span()->traceId();
        $relatedSpanId = $related->span()->spanId();
        $related->finish();

        $telemetry
            ->boundary('linked')
            ->linkedTo($this->traceOf($relatedTraceId, $relatedSpanId))
            ->linkedTo($this->traceOf(null, null))
            ->kind(SpanKind::Consumer)
            ->attributes(['after' => 'link'])
            ->from($this->rootTrace())
            ->run(static fn(): bool => true);

        $links = $this->exportedSpan(1)->getLinks();
        self::assertCount(1, $links);
        $link = $links[0] ?? Assert::fail('missing link');
        self::assertSame($relatedSpanId, $link->getSpanContext()->getSpanId());
    }
}
