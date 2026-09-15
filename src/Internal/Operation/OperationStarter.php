<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\NoopDuration;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\OwnedSpan;
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
    ) {}

    public function withoutSpan(): self
    {
        return new self(new NoOpSpanOpener(), $this->reporter);
    }

    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function start(string $name, SpanOptions $options, Duration $duration, array $attributes): RunningOperation
    {
        try {
            $span = $this->opener->open($name, $options);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Operation span start failed', $name, $throwable);
            $span = OwnedSpan::inert($name);
        }

        try {
            $timer = $duration->start();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Operation measurement start failed', $name, $throwable);
            $timer = new NoopDuration();
        }

        $span->view()->rememberErrorType($options->attributes);

        return ActiveOperation::owning($span, $timer, $attributes, $this->reporter);
    }
}
