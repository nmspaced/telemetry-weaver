<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Tracing\OwnedSpan;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OwnedSpan::class)]
final class OwnedSpanTest extends TelemetryTestCase
{
    #[Test]
    public function releasingEndsTheSpanAndRestoresTheContext(): void
    {
        $baseline = $this->contextStorage->scope();
        $owner = $this->spans->open('operation', new SpanOptions());

        self::assertNotSame($baseline, $this->contextStorage->scope());
        $owner->finish();

        self::assertSame($baseline, $this->contextStorage->scope());
        self::assertTrue($owner->isFinished());
        self::assertCount(1, $this->exported());
        $this->assertNoReports();
    }

    #[Test]
    public function repeatedReleaseExportsOnceAndReportsNothing(): void
    {
        $owner = $this->spans->open('operation', new SpanOptions());
        $owner->finish();
        $owner->finish();
        $owner->detach();

        self::assertCount(1, $this->exported());
        $this->assertNoReports();
    }

    /**
     * HTTP needs the two halves apart: the main request detaches when the
     * kernel finishes but stays open until terminate.
     */
    #[Test]
    public function detachLeavesTheSpanOpen(): void
    {
        $baseline = $this->contextStorage->scope();
        $owner = $this->spans->open('operation', new SpanOptions());
        $owner->detach();

        self::assertSame($baseline, $this->contextStorage->scope());
        self::assertSame([], $this->exported(), 'detach must not end the span');
        self::assertFalse($owner->isFinished());

        $owner->finish();
        self::assertCount(1, $this->exported());
        $this->assertNoReports();
    }

    #[Test]
    public function enrichmentStopsAtRelease(): void
    {
        $owner = $this->spans->open('operation', new SpanOptions());
        $owner->enrich(static fn(SpanInterface $span): SpanInterface => $span->setAttribute('before', true));
        $owner->finish();
        $owner->enrich(static fn(SpanInterface $span): SpanInterface => $span->setAttribute('after', true));

        $attributes = $this->exportedSpan()->getAttributes();
        self::assertTrue($attributes->get('before'));
        self::assertNull($attributes->get('after'));
    }

    /**
     * Enrichment runs inside application code, so an SDK failure there must
     * not surface as an exception the application never asked for.
     */
    #[Test]
    public function anEnrichmentFailureIsReportedNotThrown(): void
    {
        $owner = $this->spans->open('operation', new SpanOptions());
        $owner->enrich(
            /** @throws \RuntimeException always */
            static fn(): never => throw new \RuntimeException('attribute rejected'),
        );
        $owner->finish();

        self::assertCount(1, $this->exported());
        self::assertStringContainsString('span enrichment failed', $this->logger->messageAt(0));
    }

    /**
     * A detach that fails must still cost nothing but the context: the span's
     * data is already collected and ending it is the last chance to ship it.
     */
    #[Test]
    public function aFailedDetachStillEndsTheSpan(): void
    {
        $span = $this->tracer->spanBuilder('operation')->startSpan();
        $owner = OwnedSpan::activated(
            'operation',
            $span,
            new class implements ScopeInterface {
                /** @throws \RuntimeException always */
                #[\Override]
                public function detach(): int
                {
                    throw new \RuntimeException('storage is gone');
                }
            },
            $this->reporter,
        );

        $owner->finish();
        self::assertCount(1, $this->exported());
        self::assertStringContainsString('detach failed', $this->logger->messageAt(0));
    }

    /**
     * Out-of-order closing is the SDK's own signal, decoded rather than
     * suppressed: hiding it would bury the one externally visible sign that
     * the stack is not the shape we left it.
     */
    #[Test]
    public function outOfOrderClosingIsReported(): void
    {
        $owner = $this->spans->open('outer', new SpanOptions());
        $this->leak('foreign');

        $owner->finish();

        self::assertStringContainsString('closed out of order', \implode("\n", $this->logger->messages()));
    }

    /**
     * Third-party instrumentation that leaks is not ours to collect: ending
     * someone else's live span is irreversible.
     */
    #[Test]
    public function aForeignSpanIsLeftAlone(): void
    {
        $owner = $this->spans->open('ours', new SpanOptions());
        $foreign = $this->leak('foreign');

        $owner->finish();

        self::assertTrue($foreign->isRecording(), 'the package must not end a span it does not own');
        self::assertSame(['ours'], $this->exportedNames());

        $foreign->end();
    }
}
