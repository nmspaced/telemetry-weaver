<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextStorageInterface;
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

    #[Test]
    public function outOfOrderClosingIsReported(): void
    {
        $owner = $this->spans->open('outer', new SpanOptions());
        $this->leak('foreign');

        $owner->finish();

        self::assertStringContainsString('closed out of order', \implode("\n", $this->logger->messages()));
    }

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

    /** @throws \Throwable */
    #[Test]
    public function aFailedReactivationIsReportedAndLeavesTheOwnerDetached(): void
    {
        $storage = $this->createStub(ContextStorageInterface::class);
        $storage->method('attach')->willThrowException(new \RuntimeException('storage is gone'));
        $owner = $this->spans->open('operation', new SpanOptions());
        $owner->reenterableIn($storage, $this->contextStorage->current());
        $owner->detach();

        $owner->attach();

        self::assertNull($this->contextStorage->scope());
        self::assertStringContainsString('Context activation failed', $this->logger->messageAt(0));
        $owner->finish();
        self::assertSame(['operation'], $this->exportedNames());
    }
}
