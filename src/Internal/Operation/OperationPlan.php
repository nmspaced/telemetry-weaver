<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Metrics\NoopDuration;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;

/**
 * @internal Immutable recipe; its constructor cannot accidentally start a span or capture an ambient context.
 */
// @mago-expect lint:too-many-methods — immutable implementation of the complete Operation contract, its boundary extension, and its named constructor
final readonly class OperationPlan implements BoundaryOperation
{
    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $metricAttributes
     */
    private function __construct(
        private string $name,
        private OperationStarter $starter,
        private SpanOptions $options = new SpanOptions(),
        private Duration $measurement = new NoopDuration(),
        private array $metricAttributes = [],
    ) {}

    public static function named(string $name, OperationStarter $starter): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('An operation name must not be empty.');
        }

        return new self($name, $starter);
    }

    #[\Override]
    public function attributes(array $attributes): self
    {
        return $this->withOptions($this->options->with(attributes: \array_replace(
            $this->options->attributes,
            $attributes,
        )));
    }

    #[\Override]
    public function kind(SpanKind $kind): self
    {
        return $this->withOptions($this->options->with(kind: $kind));
    }

    #[\Override]
    public function from(?IncomingTrace $trace): self
    {
        return $this->withOptions($this->options->with(relations: $this->options->relations->from($trace)));
    }

    #[\Override]
    public function linkedTo(IncomingTrace $trace): self
    {
        return $this->withOptions($this->options->with(relations: $this->options->relations->linkedTo($trace)));
    }

    #[\Override]
    public function linkedToActiveSpan(): self
    {
        return $this->withOptions($this->options->with(relations: $this->options->relations->linkedToActiveSpan()));
    }

    #[\Override]
    public function withoutSpan(): self
    {
        return new self(
            $this->name,
            $this->starter->withoutSpan(),
            $this->options,
            $this->measurement,
            $this->metricAttributes,
        );
    }

    #[\Override]
    public function onlyInsideTrace(): self
    {
        return $this->withOptions($this->options->with(onlyInsideTrace: true));
    }

    #[\Override]
    public function baggage(array $entries): self
    {
        if ($entries === []) {
            return $this;
        }

        return $this->withOptions($this->options->with(baggage: \array_replace($this->options->baggage, $entries)));
    }

    #[\Override]
    public function duration(Duration $duration, array $attributes = []): self
    {
        return new self($this->name, $this->starter, $this->options, $duration, $attributes);
    }

    #[\Override]
    public function run(\Closure $work): mixed
    {
        $operation = $this->start();
        try {
            return $work(new CallbackContext($operation));
        } catch (\Throwable $throwable) {
            $operation->finish($throwable);
            throw $throwable;
        } finally {
            $operation->finish();
        }
    }

    #[\Override]
    public function start(): ScopedOperation
    {
        return $this->starter->start($this->name, $this->options, $this->measurement, $this->metricAttributes);
    }

    private function withOptions(SpanOptions $options): self
    {
        return new self($this->name, $this->starter, $options, $this->measurement, $this->metricAttributes);
    }
}
