<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The opener activates in the storage it was given, not in the process-wide one.
 *
 * Every test here holds two storages at once and never installs either as the global,
 * because that is the only arrangement in which the difference is visible: in the container
 * the injected storage *is* `Context::storage()`, so an opener that activated through the
 * static accessor would pass every ordinary test while contradicting the contract the rest
 * of the adapters — {@see OtelActiveTrace} above all — are built on.
 */
#[CoversClass(SpanOpener::class)]
final class SpanOpenerStorageTest extends TestCase
{
    private TracerProvider $tracers;

    private ContextStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        $this->tracers = new TracerProvider();
        $this->storage = new ContextStorage();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->tracers->shutdown();
    }

    #[Test]
    public function theSpanIsActivatedInTheInjectedStorage(): void
    {
        $owner = $this->opener()->open('operation', new SpanOptions());

        try {
            self::assertNotNull(new OtelActiveTrace($this->storage)->current());
        } finally {
            $owner->finish();
        }
    }

    /**
     * The other half of the same statement, and the one that failed before: activating
     * through `Context::activate()` put the span on the process storage, where nothing in
     * this package looks for it and everything else in the process does.
     */
    #[Test]
    public function theProcessStorageIsLeftAlone(): void
    {
        $owner = $this->opener()->open('operation', new SpanOptions());

        try {
            self::assertFalse(
                Span::fromContext(Context::storage()->current())->getContext()->isValid(),
                'the span must not appear in a storage the opener was not given',
            );
        } finally {
            $owner->finish();
        }
    }

    /**
     * Parentage is read from the injected storage, so it has to be written there too —
     * otherwise a nested operation reads an empty storage and starts a second root.
     */
    #[Test]
    public function aNestedOperationDescendsFromTheOuterOne(): void
    {
        $opener = $this->opener();
        $outer = $opener->open('outer', new SpanOptions());
        $inner = $opener->open('inner', new SpanOptions());

        $outerTrace = new OtelActiveTrace($this->storage)->current();

        try {
            self::assertNotNull($outerTrace);
            self::assertSame($outer->view()->traceId(), $outerTrace->traceId);
            self::assertNotSame($outer->view()->spanId(), $outerTrace->spanId, 'the inner span is on top');
        } finally {
            $inner->finish();
            $outer->finish();
        }

        self::assertNull(new OtelActiveTrace($this->storage)->current(), 'both scopes were detached');
    }

    private function opener(): SpanOpener
    {
        return new SpanOpener(
            $this->tracers->getTracer('test'),
            $this->storage,
            new InstrumentationFailureReporter(new RecordingLogger()),
        );
    }
}
