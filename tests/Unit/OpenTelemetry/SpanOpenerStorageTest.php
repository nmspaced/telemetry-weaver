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

/** The opener activates in the storage it was given, not in the process-wide one. */
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
