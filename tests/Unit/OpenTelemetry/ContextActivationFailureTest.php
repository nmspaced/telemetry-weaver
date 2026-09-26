<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceRelations;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelBaggageReader;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/** A context storage or span that fails at the edges costs the activation, never the caller. */
#[CoversClass(SpanOpener::class)]
#[CoversClass(ContextOnlyOpener::class)]
#[CoversClass(OwnedSpan::class)]
#[CoversClass(TraceRelations::class)]
final class ContextActivationFailureTest extends TelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function aSpanThatCannotBeActivatedIsEndedAndReported(): void
    {
        $opener = new SpanOpener($this->tracer, $this->unusableStorage(), $this->reporter);

        $owner = $opener->open('operation', new SpanOptions());
        $owner->finish();

        self::assertSame(['operation'], $this->exportedNames(), 'the span is ended right away, not leaked');
        self::assertStringContainsString('Context activation failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function baggageThatCannotBeActivatedIsStillKnownToTheOperation(): void
    {
        $opener = new ContextOnlyOpener($this->unusableStorage(), $this->reporter);

        $owner = $opener->open('operation', new SpanOptions(baggage: ['tenant' => 'first']));

        self::assertSame(['tenant' => 'first'], new OtelBaggageReader()->of($owner->correlation()));
        self::assertStringContainsString('Context activation failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aSpanEndFailureIsReportedNotThrown(): void
    {
        $span = $this->createStub(SpanInterface::class);
        $span->method('end')->willThrowException(new \RuntimeException('exporter is gone'));

        OwnedSpan::detached('operation', $span, $this->reporter)->finish();

        self::assertStringContainsString('span end failed', $this->logger->messageAt(0));
    }

    #[Test]
    public function attachingAnActiveOwnerDoesNotActivateItTwice(): void
    {
        $baseline = $this->contextStorage->scope();
        $owner = $this->spans->open('operation', new SpanOptions());
        $active = $this->contextStorage->scope();

        $owner->attach();

        self::assertSame($active, $this->contextStorage->scope());
        $owner->finish();
        self::assertSame($baseline, $this->contextStorage->scope());
        $this->assertNoReports();
    }

    #[Test]
    public function aLinkWithoutAnOpenTelemetryContextIsSkipped(): void
    {
        $relations = TraceRelations::ambient()->linkedTo(new RootTrace());

        $this->spans->open('operation', new SpanOptions(relations: $relations))->finish();

        self::assertSame([], $this->exportedSpan()->getLinks());
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    private function unusableStorage(): ContextStorageInterface
    {
        $storage = $this->createStub(ContextStorageInterface::class);
        $storage->method('current')->willReturn(Context::getRoot());
        $storage->method('attach')->willThrowException(new \RuntimeException('storage is gone'));

        return $storage;
    }
}
