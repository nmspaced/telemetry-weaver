<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;

/**
 * @internal Mutable owner of one span and its optional activation; a finished owner retains neither.
 */
final class OwnedSpan
{
    /**
     * @var array<int, non-empty-string>
     */
    private const array FLAG_MEANINGS = [
        ScopeInterface::DETACHED => 'context scope was already detached',
        ScopeInterface::INACTIVE => 'context scope was not active',
        ScopeInterface::MISMATCH => 'context scope closed out of order',
    ];

    private ?SpanInterface $span;

    private readonly SpanView $view;

    private ?ScopeInterface $activation;

    private bool $finished = false;

    private readonly SpanContextInterface $spanContext;

    private function __construct(
        private readonly string $name,
        SpanInterface $span,
        ?ScopeInterface $activation = null,
        private readonly ?InstrumentationFailureReporter $instrumentationFailureReporter = null,
    ) {
        $this->span = $span;
        $this->view = SpanView::borrowed($span, $this->instrumentationFailureReporter);
        $this->activation = $activation;

        try {
            $this->spanContext = $span->getContext();
        } catch (\Throwable $throwable) {
            $this->spanContext = SpanContext::getInvalid();
            $this->finish();
            $this->instrumentationFailureReporter?->report('span context read failed', $this->name, $throwable);
        }
    }

    /**
     * @param non-empty-string $name
     */
    public static function activated(
        string $name,
        SpanInterface $span,
        ScopeInterface $activation,
        InstrumentationFailureReporter $reporter,
    ): self {
        $owner = new self($name, $span, $activation, $reporter);

        if ($owner->activation !== null) {
            ShutdownScopeCleanup::register($owner);
        }

        return $owner;
    }

    /**
     * Owns an unactivated span, including one whose activation failed. @param non-empty-string $name
     */
    public static function detached(string $name, SpanInterface $span, InstrumentationFailureReporter $reporter): self
    {
        return new self($name, $span, null, $reporter);
    }

    public function view(): SpanView
    {
        return $this->view;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function spanContext(): SpanContextInterface
    {
        return $this->spanContext;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function enrich(\Closure $change): void
    {
        $span = $this->span;

        try {
            if ($span === null || !$span->isRecording()) {
                return;
            }

            $change($span);
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('span enrichment failed', $this->name, $throwable);
        }
    }

    public function detach(): void
    {
        if ($this->activation === null) {
            return;
        }

        $activation = $this->activation;
        $this->activation = null;
        ShutdownScopeCleanup::forget($this);

        try {
            $this->reportFlags($activation->detach());
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('detach failed', $this->name, $throwable);
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        try {
            $this->detach();
        } finally {
            $this->end();
        }
    }

    /**
     * @param non-empty-string $name
     */
    public static function inert(string $name): self
    {
        return new self($name, Span::getInvalid());
    }

    public function __destruct()
    {
        // exit can destroy call-stack locals before shutdown callbacks run. Release
        // only the scope: destructing abandoned work must not record success or export.
        $this->detach();
    }

    private function end(): void
    {
        $span = $this->span;
        $this->span = null;

        $this->view->release();
        $this->finished = true;

        try {
            $span?->end();
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter?->report('span end failed', $this->name, $throwable);
        }
    }

    private function reportFlags(int $flags): void
    {
        foreach (self::FLAG_MEANINGS as $flag => $meaning) {
            if (($flags & $flag) === 0) {
                continue;
            }

            $this->instrumentationFailureReporter?->report($meaning, $this->name);
        }
    }
}
