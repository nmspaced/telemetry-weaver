<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanView;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use Psr\Log\NullLogger;

/**
 * @internal A process-scoped dependency graph with no mutable execution state.
 */
final readonly class DefaultTelemetry implements Telemetry
{
    private OperationStarter $starter;

    public function __construct(
        SpanOpenerInterface $opener,
        private Metrics $instruments,
        private InstrumentationFailureReporter $reporter,
        private ContextStorageInterface $contextStorage,
    ) {
        $this->starter = new OperationStarter($opener, $reporter);
    }

    /**
     * A disabled facade still executes callbacks, without resolving providers or exporters.
     */
    public static function disabled(): self
    {
        $reporter = new InstrumentationFailureReporter(new NullLogger());

        return new self(
            new NoOpSpanOpener(),
            new SafeMetrics(new NoopMeter(), $reporter),
            $reporter,
            Context::storage(),
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
    public function metrics(): Metrics
    {
        return $this->instruments;
    }

    #[\Override]
    public function currentSpan(): Span
    {
        try {
            return SpanView::current(OtelSpan::fromContext($this->contextStorage->current()), $this->reporter);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Current span resolution failed', self::class, $throwable);

            return SpanView::borrowed(OtelSpan::getInvalid(), $this->reporter);
        }
    }
}
