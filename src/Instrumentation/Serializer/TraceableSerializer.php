<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Serializer;

use Nmspaced\TelemetryWeaver\Api\Span;
use Symfony\Component\Serializer\Encoder\ContextAwareDecoderInterface;
use Symfony\Component\Serializer\Encoder\ContextAwareEncoderInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

// @mago-expect lint:too-many-methods — implements the full serializer interfaces
final readonly class TraceableSerializer implements
    SerializerInterface,
    NormalizerInterface,
    DenormalizerInterface,
    ContextAwareEncoderInterface,
    ContextAwareDecoderInterface
{
    /**
     * @param non-empty-string $serializerName
     */
    public function __construct(
        private SerializerInterface&NormalizerInterface&DenormalizerInterface&ContextAwareEncoderInterface&ContextAwareDecoderInterface $delegate,
        private SerializerTelemetry $serializerTelemetry,
        private string $serializerName = 'default',
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Throwable
     */
    #[\Override]
    public function serialize(mixed $data, string $format, array $context = []): string
    {
        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            $format,
            ['serializer.data.type' => \get_debug_type($data)],
            /** @throws ExceptionInterface */
            function (Span $span) use ($data, $format, $context): string {
                $payload = $this->delegate->serialize($data, $format, $context);
                $this->payload(\strlen($payload), $span);

                return $payload;
            },
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Throwable
     */
    #[\Override]
    public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
    {
        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            $format,
            ['serializer.type' => $type],
            /** @throws ExceptionInterface */
            function (Span $span) use ($data, $type, $format, $context): mixed {
                if (\is_string($data)) {
                    $this->payload(\strlen($data), $span);
                }

                return $this->delegate->deserialize($data, $type, $format, $context);
            },
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Throwable
     */
    #[\Override]
    public function normalize(
        mixed $data,
        ?string $format = null,
        array $context = [],
    ): array|string|int|float|bool|\ArrayObject|null {
        return $this->run(
            __FUNCTION__,
            $format,
            ['serializer.data.type' => \get_debug_type($data)],
            /**
             * @return array<array-key, mixed>|string|int|float|bool|\ArrayObject<array-key, mixed>|null
             *
             * @throws ExceptionInterface
             */
            fn(): array|string|int|float|bool|\ArrayObject|null => $this->delegate->normalize($data, $format, $context),
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Throwable
     */
    #[\Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        return $this->run(
            __FUNCTION__,
            $format,
            ['serializer.type' => $type],
            /** @throws ExceptionInterface */
            fn(): mixed => $this->delegate->denormalize($data, $type, $format, $context),
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Throwable
     */
    #[\Override]
    public function encode(mixed $data, string $format, array $context = []): string
    {
        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            $format,
            ['serializer.data.type' => \get_debug_type($data)],
            /** @throws ExceptionInterface */
            function (Span $span) use ($data, $format, $context): string {
                $payload = $this->delegate->encode($data, $format, $context);
                $this->payload(\strlen($payload), $span);

                return $payload;
            },
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Throwable
     */
    #[\Override]
    public function decode(string $data, string $format, array $context = []): mixed
    {
        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            $format,
            [],
            /** @throws ExceptionInterface */
            function (Span $span) use ($data, $format, $context): mixed {
                $this->payload(\strlen($data), $span);

                return $this->delegate->decode($data, $format, $context);
            },
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->delegate->supportsNormalization($data, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = [],
    ): bool {
        return $this->delegate->supportsDenormalization($data, $type, $format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function supportsEncoding(string $format, array $context = []): bool
    {
        return $this->delegate->supportsEncoding($format, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    #[\Override]
    public function supportsDecoding(string $format, array $context = []): bool
    {
        return $this->delegate->supportsDecoding($format, $context);
    }

    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return $this->delegate->getSupportedTypes($format);
    }

    /**
     * @template T
     *
     * @param non-empty-string $operation
     * @param array<non-empty-string, bool|float|int|string|list<string>> $attributes
     * @param \Closure(Span): T $callback
     * @param-immediately-invoked-callable $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    private function run(string $operation, ?string $format, array $attributes, \Closure $callback): mixed
    {
        return $this->serializerTelemetry->run($this->serializerName, $operation, $format, $attributes, $callback);
    }

    /**
     * @param int<0, max> $bytes
     */
    private function payload(int $bytes, Span $span): void
    {
        $this->serializerTelemetry->payload($bytes, $span);
    }
}
