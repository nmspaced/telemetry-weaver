<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Durations;
use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\InertSpan;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;

/**
 * @internal Starts the independent signals without letting either failure prevent business execution.
 */
final readonly class OperationStarter
{
    public function __construct(
        private SpanOpenerInterface $opener,
        private InstrumentationFailureReporter $reporter,
        private BaggageReader $baggage,
    ) {}

    public function withoutSpan(): self
    {
        return new self($this->opener->suppressed(), $this->reporter, $this->baggage);
    }

    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function start(string $name, SpanOptions $options, Duration $duration, array $attributes): ScopedOperation
    {
        try {
            $span = $this->opener->open($name, $options);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Operation span start failed', $name, $throwable);
            $span = new InertSpan($name);
        }

        $timer = Durations::start($duration, $span->correlation(), $this->reporter, $name);

        $span->rememberErrorType($options->attributes);

        return ActiveOperation::owning($span, $timer, $attributes, $this->reporter, $this->baggage);
    }
}
