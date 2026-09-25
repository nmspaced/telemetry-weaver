<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Tests\Support\TraceableSerializerTestCase;
use OpenTelemetry\API\Trace\StatusCode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Span shape for each serializer operation: attributes, error recording, and which operations open
 * spans at all. Metric/duration and messenger-decoding scenarios live in {@see
 * TraceableSerializerMetricsTest}.
 */
final class TraceableSerializerTest extends TraceableSerializerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function serializeIsMeasuredAndCarriesFormatAndPayloadSize(): void
    {
        $this->activateParent();
        $serializer = $this->serializer();

        self::assertSame('{"name":"otel"}', $serializer->serialize(['name' => 'otel'], 'json'));
        self::assertSame(['serializer.serialize'], $this->exportedNames());
        self::assertSame(
            [
                'serializer.name' => 'default',
                'serializer.operation.name' => 'serialize',
                'serializer.format' => 'json',
                'serializer.data.type' => 'array',
                'serializer.payload.size' => 15,
            ],
            $this->exportedSpan()->getAttributes()->toArray(),
        );
        self::assertSame([], $this->logger->messages());
    }

    /** @throws \Throwable */
    #[Test]
    public function deserializeRecordsTheTargetTypeAndTheInputSize(): void
    {
        $this->activateParent();
        $serializer = $this->serializer();
        $payload = '"2026-01-01T00:00:00+00:00"';

        $date = $serializer->deserialize($payload, \DateTimeImmutable::class, 'json');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);

        $attributes = $this->exportedSpan()->getAttributes()->toArray();
        self::assertSame('serializer.deserialize', $this->exportedSpan()->getName());
        self::assertSame(\DateTimeImmutable::class, $attributes['serializer.type'] ?? null);
        self::assertSame(\strlen($payload), $attributes['serializer.payload.size'] ?? null);
    }

    /** @throws \Throwable */
    #[Test]
    public function normalizerOperationsSpanWithoutAFormatAttribute(): void
    {
        $this->activateParent();
        $serializer = $this->serializer();

        self::assertSame(['name' => 'otel'], $serializer->normalize(new \ArrayObject(['name' => 'otel'])));
        self::assertSame(
            [
                'serializer.name' => 'default',
                'serializer.operation.name' => 'normalize',
                'serializer.data.type' => 'ArrayObject',
            ],
            $this->exportedSpan()->getAttributes()->toArray(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function denormalizeRecordsTheTargetTypeAndTheFormat(): void
    {
        $this->activateParent();
        $serializer = $this->serializer();

        $date = $serializer->denormalize('2026-01-01T00:00:00+00:00', \DateTimeImmutable::class, 'json');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame(
            [
                'serializer.name' => 'default',
                'serializer.operation.name' => 'denormalize',
                'serializer.format' => 'json',
                'serializer.type' => \DateTimeImmutable::class,
            ],
            $this->exportedSpan()->getAttributes()->toArray(),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function encodeAndDecodeAreMeasuredSeparately(): void
    {
        $this->activateParent();
        $serializer = $this->serializer();

        self::assertSame('{"name":"otel"}', $serializer->encode(['name' => 'otel'], 'json'));
        self::assertSame(['name' => 'otel'], $serializer->decode('{"name":"otel"}', 'json'));
        self::assertSame(['serializer.encode', 'serializer.decode'], $this->exportedNames());
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailedOperationIsRecordedOnTheSpanAndRethrown(): void
    {
        $this->activateParent();
        $serializer = $this->serializer();

        $this->expectException(NotEncodableValueException::class);

        try {
            $serializer->deserialize('{ not json', 'array', 'json');
        } finally {
            $span = $this->exportedSpan();
            self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            $event = $span->getEvents()[0] ?? Assert::fail('span has no events');
            self::assertSame('exception', $event->getName());
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function supportChecksArePassedThroughWithoutASpan(): void
    {
        $serializer = $this->serializer();

        $inner = $this->inner();

        self::assertTrue($serializer->supportsEncoding('json'));
        self::assertTrue($serializer->supportsDecoding('json'));
        self::assertFalse($serializer->supportsEncoding('xml'));
        self::assertTrue($serializer->supportsNormalization(new \DateTimeImmutable()));
        self::assertFalse($serializer->supportsNormalization(new \stdClass()));
        self::assertTrue($serializer->supportsDenormalization('now', \DateTimeImmutable::class, 'json'));
        self::assertSame($inner->getSupportedTypes(null), $serializer->getSupportedTypes(null));
        self::assertSame([], $this->exportedNames(), 'support checks must not open spans');
    }
}
