<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\SpanKind;

/**
 * @internal
 *
 * @phpstan-type AttributeValue string|int|float|bool|null|list<string|int|float|bool>
 */
final readonly class SpanOptions
{
    public TraceRelations $relations;

    /**
     * @param array<non-empty-string, AttributeValue> $attributes
     * @param TraceRelations|null $relations null is {@see TraceRelations::ambient()} — the
     *                                       span descends from whatever is already running
     * @param bool $onlyInsideTrace open no span unless something is already being traced
     * @param array<non-empty-string, string> $baggage entries to carry for the operation's scope
     */
    public function __construct(
        public array $attributes = [],
        public SpanKind $kind = SpanKind::Internal,
        ?TraceRelations $relations = null,
        public bool $onlyInsideTrace = false,
        public array $baggage = [],
    ) {
        $this->relations = $relations ?? TraceRelations::ambient();
    }

    /**
     * @param array<non-empty-string, AttributeValue>|null $attributes
     * @param array<non-empty-string, string>|null $baggage
     */
    public function with(
        ?array $attributes = null,
        ?SpanKind $kind = null,
        ?TraceRelations $relations = null,
        ?bool $onlyInsideTrace = null,
        ?array $baggage = null,
    ): self {
        return new self(
            $attributes ?? $this->attributes,
            $kind ?? $this->kind,
            $relations ?? $this->relations,
            $onlyInsideTrace ?? $this->onlyInsideTrace,
            $baggage ?? $this->baggage,
        );
    }
}
