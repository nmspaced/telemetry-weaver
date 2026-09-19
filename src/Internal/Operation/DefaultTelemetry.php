<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DiscardedDurations;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoBaggage;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use Psr\Log\NullLogger;

/**
 * @internal A process-scoped dependency graph with no mutable execution state.
 */
final readonly class DefaultTelemetry implements BoundaryTelemetry
{
    private OperationStarter $starter;

    public function __construct(
        SpanOpenerInterface $opener,
        private Metrics $instruments,
        InstrumentationFailureReporter $reporter,
        BaggageReader $baggage,
    ) {
        $this->starter = new OperationStarter($opener, $reporter, $baggage);
    }

    /**
     * A disabled facade still executes callbacks, without resolving providers or exporters.
     */
    public static function disabled(): self
    {
        $reporter = new InstrumentationFailureReporter(new NullLogger());

        return new self(
            NoOpSpanOpener::disabled(),
            new SafeMetrics(new NoopMeter(), $reporter, new DiscardedDurations()),
            $reporter,
            new NoBaggage(),
        );
    }

    #[\Override]
    public function trace(string $name, \Closure $work, array $attributes = []): mixed
    {
        return $this
            ->operation($name)
            ->attributes($attributes)
            ->run(static fn(OperationContext $context): mixed => $work($context->span()));
    }

    #[\Override]
    public function operation(string $name): Operation
    {
        return OperationPlan::named($name, $this->starter);
    }

    #[\Override]
    public function boundary(string $name): BoundaryOperation
    {
        return OperationPlan::named($name, $this->starter);
    }

    #[\Override]
    public function metrics(): Metrics
    {
        return $this->instruments;
    }
}
