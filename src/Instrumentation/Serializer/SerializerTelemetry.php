<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Serializer;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;

/**
 * @internal
 * Serializer work enriches an existing trace; it is not an execution boundary.
 * Messenger decodes its body and stamps before the consumer span opens, so tracing
 * those calls without a parent produces unrelated single-span traces per delivery.
 * Their duration is still measured, including failures, even without a trace.
 */
final readonly class SerializerTelemetry
{
    private Duration $duration;

    public function __construct(
        private BoundaryTelemetry $telemetry,
        OperationBuckets $buckets = DefaultBuckets::Serializer,
    ) {
        $this->duration = $telemetry->metrics()->duration(
            'serializer.operation.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of serializer operations.',
        );
    }

    /**
     * @template T
     *
     * @param non-empty-string $serializer
     *
     * @param non-empty-string $operation
     *
     * @param array<non-empty-string, bool|float|int|string|list<string>> $spanAttributes
     *
     * @param \Closure(Span): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    public function run(
        string $serializer,
        string $operation,
        ?string $format,
        array $spanAttributes,
        \Closure $callback,
    ): mixed {
        $attributes = $this->attributes($serializer, $operation, $format);

        return $this->telemetry
            ->boundary(\sprintf('serializer.%s', $operation))
            ->attributes($attributes + $spanAttributes)
            ->duration($this->duration, attributes: $attributes)
            ->onlyInsideTrace()
            ->run(static fn(OperationContext $context): mixed => $callback($context->span()));
    }

    /**
     * Payload size belongs only to the span, never to duration labels.
     * @param int<0, max> $bytes
     */
    public function payload(int $bytes, Span $span): void
    {
        $span->attribute('serializer.payload.size', $bytes);
    }

    /**
     * @param non-empty-string $serializer
     *
     * @param non-empty-string $operation
     *
     * @return array<non-empty-string, string>
     */
    private function attributes(string $serializer, string $operation, ?string $format): array
    {
        $attributes = [
            'serializer.name' => $serializer,
            'serializer.operation.name' => $operation,
        ];

        if ($format !== null && $format !== '') {
            $attributes['serializer.format'] = $format;
        }

        return $attributes;
    }
}
