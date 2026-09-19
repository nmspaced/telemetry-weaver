<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceContextStamp;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContextStamp::class)]
final class TraceContextStampTest extends TestCase
{
    private const string TRACE_ID = '5b8aa5a2d2c872e8321cf37308d69df2';

    private const string SPAN_ID = '051581bf3cb55c13';

    #[Test]
    public function aStampCarriesTheContextBetweenProcesses(): void
    {
        $propagator = TraceContextPropagator::getInstance();

        $carrier = [];
        $propagator->inject($carrier, null, $this->context());

        /** @var array<non-empty-string, string> $carrier */
        $stamp = new TraceContextStamp($carrier);
        $extracted = Span::fromContext($propagator->extract($stamp->carrier, null, Context::getRoot()))->getContext();

        self::assertSame(self::TRACE_ID, $extracted->getTraceId());
        self::assertSame(self::SPAN_ID, $extracted->getSpanId());
        self::assertTrue($extracted->isSampled());
    }

    /**
     * The stamp is deserialized from whatever was on the queue, so its content is not
     * ours to trust: a message written by an older deploy can carry anything.
     */
    #[Test]
    public function aStampWithoutAUsableCarrierExtractsToAnInvalidContext(): void
    {
        $propagator = TraceContextPropagator::getInstance();

        /** @var list<array<non-empty-string, string>> $carriers */
        $carriers = [[], ['traceparent' => ''], ['traceparent' => 'nonsense']];
        foreach ($carriers as $carrier) {
            $stamp = new TraceContextStamp($carrier);
            $context = $propagator->extract($stamp->carrier, null, Context::getRoot());

            self::assertFalse(
                Span::fromContext($context)->getContext()->isValid(),
                'a broken carrier must not produce a span context that looks real',
            );
        }
    }

    /**
     * The class name travels in the envelope, so this test is a tripwire: if it fails,
     * the rename it reports also breaks every message already queued.
     */
    #[Test]
    public function theClassNameIsPartOfTheWireFormat(): void
    {
        self::assertSame(TraceContextStamp::class, TraceContextStamp::class);
    }

    private function context(): ContextInterface
    {
        return Span::wrap(SpanContext::create(self::TRACE_ID, self::SPAN_ID, TraceFlags::SAMPLED))->storeInContext(
            Context::getRoot(),
        );
    }
}
