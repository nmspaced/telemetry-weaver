<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The adapter reads the storage it was given, never the process-wide one. */
#[CoversClass(OtelActiveTrace::class)]
final class OtelActiveTraceTest extends TestCase
{
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    private const string SPAN_ID = 'b7ad6b7169203331';

    #[Test]
    public function nothingRunningIsNothingToName(): void
    {
        self::assertNull(new OtelActiveTrace(new ContextStorage())->current());
    }

    #[Test]
    public function theCurrentSpanIsNamedByItsIds(): void
    {
        $storage = new ContextStorage();
        $scope = $this->attach($storage, TraceFlags::SAMPLED);

        $current = new OtelActiveTrace($storage)->current();

        $scope->detach();

        self::assertNotNull($current);
        self::assertSame(self::TRACE_ID, $current->traceId);
        self::assertSame(self::SPAN_ID, $current->spanId);
        self::assertTrue($current->sampled());
    }

    #[Test]
    public function anUnsampledTraceIsNamedAndSaysSo(): void
    {
        $storage = new ContextStorage();
        $scope = $this->attach($storage, TraceFlags::DEFAULT);

        $current = new OtelActiveTrace($storage)->current();

        $scope->detach();

        self::assertNotNull($current);
        self::assertSame(self::TRACE_ID, $current->traceId);
        self::assertFalse($current->sampled());
    }

    #[Test]
    public function anInvalidContextIsAbsenceRatherThanZeroes(): void
    {
        $storage = new ContextStorage();
        $scope = $storage->attach(Context::getRoot()->withContextValue(Span::getInvalid()));

        $current = new OtelActiveTrace($storage)->current();

        $scope->detach();

        self::assertNull($current);
    }

    #[Test]
    public function aStorageThatThrowsReadsAsNothing(): void
    {
        $storage = new class implements ContextStorageInterface {
            #[\Override]
            public function scope(): ?ContextStorageScopeInterface
            {
                return null;
            }

            /** @throws \RuntimeException always; that is what it is for */
            #[\Override]
            public function current(): ContextInterface
            {
                throw new \RuntimeException('storage is gone');
            }

            /** @throws \RuntimeException always; that is what it is for */
            #[\Override]
            public function attach(ContextInterface $context): ContextStorageScopeInterface
            {
                throw new \RuntimeException('storage is gone');
            }
        };

        $current = new OtelActiveTrace($storage)->current();

        self::assertNull($current);
    }

    #[Test]
    public function nestedAndRemoteContextsRestoreTheParentWithoutChangingSnapshots(): void
    {
        $storage = new ContextStorage();
        $reader = new OtelActiveTrace($storage);
        $parentScope = $this->attach($storage, TraceFlags::SAMPLED);

        try {
            $parent = $reader->current();
            self::assertNotNull($parent);
            $childId = '0123456789abcdef';
            $childScope = $storage->attach(Context::getRoot()->withContextValue(Span::wrap(SpanContext::createFromRemoteParent(
                self::TRACE_ID,
                $childId,
                TraceFlags::DEFAULT,
            ))));

            try {
                $child = $reader->current();
                self::assertNotNull($child);
                self::assertSame($childId, $child->spanId);
                self::assertFalse($child->sampled());
            } finally {
                $childScope->detach();
            }

            self::assertEquals($parent, $reader->current());
            self::assertSame($childId, $child->spanId);
        } finally {
            $parentScope->detach();
        }

        self::assertNull($reader->current());
        self::assertSame(self::SPAN_ID, $parent->spanId);
    }

    #[Test]
    public function invalidFlagsFromASpanContextAreNotSilentlyWrapped(): void
    {
        $storage = new ContextStorage();
        $scope = $this->attach($storage, 257);

        try {
            self::assertNull(new OtelActiveTrace($storage)->current());
        } finally {
            $scope->detach();
        }
    }

    private function attach(ContextStorageInterface $storage, int $flags): ScopeInterface
    {
        return $storage->attach(Context::getRoot()->withContextValue(Span::wrap(SpanContext::create(
            self::TRACE_ID,
            self::SPAN_ID,
            $flags,
        ))));
    }
}
