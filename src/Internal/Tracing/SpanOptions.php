<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * @phpstan-type AttributeValue string|int|float|bool|null|list<string|int|float|bool>
 */
final readonly class SpanOptions
{
    /**
     * @param array<non-empty-string, AttributeValue> $attributes
     * @param int<0, 4> $kind one of the SpanKind constants
     * @param ContextInterface|null $parent null means "current context at creation time"
     * @param list<SpanContextInterface> $links spans this one is related to without descending from
     */
    public function __construct(
        public array $attributes = [],
        public int $kind = SpanKind::KIND_INTERNAL,
        public ?ContextInterface $parent = null,
        public array $links = [],
    ) {}

    /**
     * @param array<non-empty-string, AttributeValue> $attributes
     */
    public static function server(array $attributes = [], ?ContextInterface $parent = null): self
    {
        return new self($attributes, SpanKind::KIND_SERVER, $parent);
    }

    /**
     * @param array<non-empty-string, AttributeValue> $attributes
     */
    public static function consumer(array $attributes = [], ?ContextInterface $parent = null): self
    {
        return new self($attributes, SpanKind::KIND_CONSUMER, $parent);
    }

    /**
     * @param array<non-empty-string, AttributeValue> $attributes
     */
    public static function producer(array $attributes = [], ?ContextInterface $parent = null): self
    {
        return new self($attributes, SpanKind::KIND_PRODUCER, $parent);
    }

    /**
     * @param array<non-empty-string, AttributeValue> $attributes
     */
    public static function client(array $attributes = [], ?ContextInterface $parent = null): self
    {
        return new self($attributes, SpanKind::KIND_CLIENT, $parent);
    }

    /**
     * @param int<0, 4> $kind
     * @param array<non-empty-string, AttributeValue> $attributes
     */
    public static function root(int $kind = SpanKind::KIND_INTERNAL, array $attributes = []): self
    {
        return new self($attributes, $kind, Context::getRoot());
    }
}
