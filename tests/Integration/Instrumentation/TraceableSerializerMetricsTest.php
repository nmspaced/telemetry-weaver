<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Support\TraceableSerializerTestCase;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\Serializer as MessengerSerializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Duration-histogram shape and messenger-decoding trace boundaries. Span-attribute scenarios
 * for individual operations live in {@see TraceableSerializerTest}.
 */
final class TraceableSerializerMetricsTest extends TraceableSerializerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theDurationHistogramIsRecordedPerOperation(): void
    {
        $clock = new FrozenClock();
        $serializer = $this->serializer($clock);

        $serializer->serialize(['name' => 'otel'], 'json');

        $this->meters->forceFlush();

        $duration = $this->histogram('serializer.operation.duration');
        self::assertSame(1, $duration->count);
        self::assertSame(
            [
                'serializer.name' => 'default',
                'serializer.operation.name' => 'serialize',
                'serializer.format' => 'json',
            ],
            $duration->attributes->toArray(),
        );

        self::assertSame(
            ['serializer.operation.duration'],
            $this->recordedMetricNames(),
            'the payload size stays a span attribute, not a metric',
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function messengerDecodingDoesNotCreateRootTracesAndLaterWorkUsesTheCurrentParent(): void
    {
        $serializer = $this->serializer(
            inner: new Serializer([
                new DateTimeNormalizer(),
                new ArrayDenormalizer(),
                new class implements DenormalizerInterface {
                    #[\Override]
                    public function denormalize(
                        mixed $data,
                        string $type,
                        ?string $format = null,
                        array $context = [],
                    ): BusNameStamp {
                        \assert(
                            \is_array($data),
                            'the messenger transport always denormalizes a stamp from a decoded array',
                        );

                        return new BusNameStamp((string) ($data['busName'] ?? ''));
                    }

                    #[\Override]
                    public function supportsDenormalization(
                        mixed $data,
                        string $type,
                        ?string $format = null,
                        array $context = [],
                    ): bool {
                        return $type === BusNameStamp::class;
                    }

                    #[\Override]
                    public function getSupportedTypes(?string $format): array
                    {
                        return [BusNameStamp::class => true];
                    }
                },
            ], [new JsonEncoder()]),
        );
        $transport = new MessengerSerializer($serializer);
        $envelope = $transport->decode([
            'body' => '"2026-01-01T00:00:00+00:00"',
            'headers' => [
                'type' => \DateTimeImmutable::class,
                'X-Message-Stamp-' . BusNameStamp::class => '[{"busName":"messenger.bus.command"}]',
            ],
        ]);

        self::assertInstanceOf(\DateTimeImmutable::class, $envelope->getMessage());
        self::assertSame('messenger.bus.command', $envelope->last(BusNameStamp::class)?->getBusName());
        self::assertSame([], $this->exportedNames());
        self::assertNull(Context::storage()->scope());
        $this->meters->forceFlush();
        self::assertSame(2, $this->histogram('serializer.operation.duration')->count);

        $this->activateParent();
        $serializer->serialize(['inside' => true], 'json');
        self::assertSame(['serializer.serialize'], $this->exportedNames());
        self::assertSame($this->parent?->getContext()->getSpanId(), $this->exportedSpan()->getParentSpanId());
        self::assertSame($this->parent?->getContext()->getTraceId(), $this->exportedSpan()->getContext()->getTraceId());
    }

    /** @throws \Throwable */
    #[Test]
    public function failuresWithoutAParentKeepTheirMetricsAndOriginalException(): void
    {
        $serializer = $this->serializer();
        $this->expectException(NotEncodableValueException::class);

        try {
            $serializer->deserialize('{ not json', 'array', 'json');
        } finally {
            self::assertSame([], $this->exportedNames());
            $this->meters->forceFlush();
            $duration = $this->histogram('serializer.operation.duration');
            self::assertSame(1, $duration->count);
            self::assertSame(NotEncodableValueException::class, $duration->attributes->get('error.type'));
            self::assertNull(Context::storage()->scope());
        }
    }
}
