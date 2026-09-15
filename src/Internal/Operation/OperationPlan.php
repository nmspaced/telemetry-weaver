<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Metrics\NoopDuration;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;

/**
 * @internal Immutable recipe; its constructor cannot accidentally start a span or capture an ambient context.
 */
// @mago-expect lint:too-many-methods — immutable implementation of the complete Operation contract, including its named constructor
final readonly class OperationPlan implements Operation
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
    public function attributes(array $attributes): Operation
    {
        return $this->withOptions(
            new SpanOptions(
                \array_replace($this->options->attributes, $attributes),
                $this->options->kind,
                $this->options->parent,
                $this->options->links,
            ),
        );
    }

    #[\Override]
    public function kind(SpanKind $kind): Operation
    {
        return $this->withOptions(
            new SpanOptions($this->options->attributes, $kind->value, $this->options->parent, $this->options->links),
        );
    }

    #[\Override]
    public function parent(ContextInterface $context): Operation
    {
        return $this->withOptions(
            new SpanOptions($this->options->attributes, $this->options->kind, $context, $this->options->links),
        );
    }

    #[\Override]
    public function root(): Operation
    {
        return $this->parent(Context::getRoot());
    }

    #[\Override]
    public function link(SpanContextInterface $context): Operation
    {
        if (!$context->isValid()) {
            return $this;
        }

        return $this->withOptions(new SpanOptions(
            $this->options->attributes,
            $this->options->kind,
            $this->options->parent,
            [...$this->options->links, $context],
        ));
    }

    #[\Override]
    public function withoutSpan(): Operation
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
    public function duration(Duration $duration, array $attributes = []): Operation
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
    public function start(): RunningOperation
    {
        return $this->starter->start($this->name, $this->options, $this->measurement, $this->metricAttributes);
    }

    private function withOptions(SpanOptions $options): self
    {
        return new self($this->name, $this->starter, $options, $this->measurement, $this->metricAttributes);
    }
}
