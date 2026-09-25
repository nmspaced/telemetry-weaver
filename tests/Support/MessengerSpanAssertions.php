<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Messenger\TraceContextStamp;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Assert;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Reads exported Messenger spans and propagated stamps, failing the test when one is missing.
 *
 * @internal
 */
final class MessengerSpanAssertions
{
    private function __construct() {}

    /** @return list<string> */
    public static function exportedNames(InMemoryExporter $spans): array
    {
        return \array_map(static fn(ImmutableSpan $span): string => $span->getName(), self::all($spans));
    }

    public static function spanNamed(InMemoryExporter $spans, string $name): ImmutableSpan
    {
        foreach (self::all($spans) as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        Assert::fail(\sprintf('no span named "%s" among [%s]', $name, \implode(', ', self::exportedNames($spans))));
    }

    /** @return list<ImmutableSpan> */
    public static function spansNamed(InMemoryExporter $spans, string $name): array
    {
        return \array_values(\array_filter(
            self::all($spans),
            static fn(ImmutableSpan $span): bool => $span->getName() === $name,
        ));
    }

    /** @return array<string, string> the carrier the transport's copy is carrying */
    public static function stampOf(InMemoryTransport $transport): array
    {
        $envelope = $transport->getSent()[0] ?? null;
        Assert::assertInstanceOf(Envelope::class, $envelope);

        $stamp = $envelope->last(TraceContextStamp::class);
        Assert::assertInstanceOf(TraceContextStamp::class, $stamp);

        return $stamp->carrier;
    }

    /** @return list<ImmutableSpan> */
    private static function all(InMemoryExporter $spans): array
    {
        $all = \array_values($spans->getSpans());
        Assert::assertContainsOnlyInstancesOf(ImmutableSpan::class, $all);

        return $all;
    }
}
