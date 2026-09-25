<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Api;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The value object hands out a wire format, which makes it the one place in this package where a
 * specification is written out by hand rather than delegated.
 */
#[CoversClass(TraceContext::class)]
final class TraceContextTest extends TestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string SPAN_ID = 'b7ad6b7169203331';

    /** @return iterable<string, array{int<0, 255>}> */
    public static function flags(): iterable
    {
        for ($flags = 0; $flags <= 255; ++$flags) {
            yield \sprintf('%02x', $flags) => [$flags];
        }
    }

    /** @param int<0, 255> $flags */
    #[Test]
    #[DataProvider('flags')]
    public function theTraceparentMatchesWhatThePropagatorWrites(int $flags): void
    {
        $spanContext = SpanContext::create(self::TRACE_ID, self::SPAN_ID, $flags);
        $carrier = [];
        TraceContextPropagator::getInstance()->inject(
            $carrier,
            null,
            Context::getRoot()->withContextValue(Span::wrap($spanContext)),
        );

        $context = new TraceContext(self::TRACE_ID, self::SPAN_ID, $flags);

        self::assertSame($carrier[TraceContextPropagator::TRACEPARENT] ?? null, $context->traceparent());
    }

    #[Test]
    public function samplingIsBitZeroAndNothingElse(): void
    {
        self::assertTrue(new TraceContext(self::TRACE_ID, self::SPAN_ID, 0x01)->sampled());
        self::assertTrue(new TraceContext(self::TRACE_ID, self::SPAN_ID, 0x03)->sampled());
        self::assertFalse(new TraceContext(self::TRACE_ID, self::SPAN_ID, 0x00)->sampled());
        self::assertFalse(new TraceContext(self::TRACE_ID, self::SPAN_ID, 0x02)->sampled());
    }

    #[Test]
    public function theFlagsAreAlwaysTwoLowercaseHexDigits(): void
    {
        self::assertSame('00', new TraceContext(self::TRACE_ID, self::SPAN_ID, 0)->traceFlagsHex());
        self::assertSame('01', new TraceContext(self::TRACE_ID, self::SPAN_ID, 1)->traceFlagsHex());
        self::assertSame('0f', new TraceContext(self::TRACE_ID, self::SPAN_ID, 15)->traceFlagsHex());
        self::assertSame('ff', new TraceContext(self::TRACE_ID, self::SPAN_ID, 255)->traceFlagsHex());
    }

    #[Test]
    public function theIdsAreTheOnesItWasGiven(): void
    {
        $context = new TraceContext(self::TRACE_ID, self::SPAN_ID, TraceFlags::SAMPLED);

        self::assertSame(self::TRACE_ID, $context->traceId);
        self::assertSame(self::SPAN_ID, $context->spanId);
        self::assertSame(TraceFlags::SAMPLED, $context->traceFlags);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidContexts(): iterable
    {
        yield 'empty trace' => ['', self::SPAN_ID, 0];
        yield 'short trace' => ['abc', self::SPAN_ID, 0];
        yield 'long trace' => [self::TRACE_ID . '0', self::SPAN_ID, 0];
        yield 'uppercase trace' => [\strtoupper(self::TRACE_ID), self::SPAN_ID, 0];
        yield 'zero trace' => [\str_repeat('0', 32), self::SPAN_ID, 0];
        yield 'non-hex trace' => [\str_repeat('g', 32), self::SPAN_ID, 0];
        yield 'empty span' => [self::TRACE_ID, '', 0];
        yield 'short span' => [self::TRACE_ID, 'abc', 0];
        yield 'long span' => [self::TRACE_ID, self::SPAN_ID . '0', 0];
        yield 'uppercase span' => [self::TRACE_ID, \strtoupper(self::SPAN_ID), 0];
        yield 'zero span' => [self::TRACE_ID, \str_repeat('0', 16), 0];
        yield 'non-hex span' => [self::TRACE_ID, \str_repeat('g', 16), 0];
        yield 'newline' => [self::TRACE_ID, self::SPAN_ID . "\n", 0];
        yield 'negative flags' => [self::TRACE_ID, self::SPAN_ID, -1];
        yield 'overflow flags' => [self::TRACE_ID, self::SPAN_ID, 256];
    }

    #[Test]
    #[DataProvider('invalidContexts')]
    public function invalidValuesCannotBecomeACorrelationField(string $traceId, string $spanId, int $flags): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TraceContext($traceId, $spanId, $flags);
    }
}
